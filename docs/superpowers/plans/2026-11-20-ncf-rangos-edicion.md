# Editar rangos e-NCF — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A user with the `ncf` module corrects a registered e-NCF range (expiry date, authorization numbers, "Hasta", and "Desde" while unused) from the "Rangos e-NCF autorizados" modal, and date-only values stop showing one day early.

**Architecture:** `ncfModel::updateRange` enforces every rule in one transaction (row lock on the type's ranges; "last used" = counter and invoices). `ncfController` exposes it as `PUT /api/ncf/rangos/{id}` with an audit entry. In `fiscalo`, the modal gets a ✎ per range row and an edit form that mirrors the rules (`problemaConEdicion`) and sends only what changed.

**Tech Stack:** `api-gratex`: PHP 8.3, MySQL, no Composer; tests are pure PHP scripts with a scripted fake connection. `fiscalo`: React 18 + TypeScript + Vite + TanStack Query; tests are Node scripts (`node scripts/test-*.ts`, Node 25 strips types).

**Spec:** `docs/specs/ncf-rangos-edicion.md` (read it first; this plan does not repeat its rules).

## Global Constraints

- No migration. No new permission: the route stays under module `ncf` (`config/permissions.php`).
- Body fields: `fecha_vencimiento` (`Y-m-d`), `no_solicitud`, `no_autorizacion` (hasta 20 caracteres, vacío = sin dato), `numero_hasta`, `numero_desde`. `type`, `ambiente` or `current_value` in the body → 422.
- Responses: `200` with the range in `GET` shape (`usados`, `restantes`, `estado`); `404` if the `id` is not a range of the active ambiente; `422` with Spanish text per rule; `400` if the body can't be read. No changes → `200`, no write, no audit.
- Audit: `NCF_RANGE_UPDATE`, module `ncf`, entity `ncf_rango`, `old_values`/`new_values` with only what changed, plus the range `id`.
- User-facing text in Spanish, same tone as `ncfModel::registerRange`.
- `api-gratex`: PHP 8.3 syntax only; files are CRLF (keep it); no server, mock backend or browser (the user runs those); verify with `php -l` and the pure suites.
- `fiscalo`: in Node-tested modules, value imports by relative path with `.ts`; `@/…` only as `import type`.
- Git: never `git add -A` or `git add .` (both trees carry unrelated uncommitted work); each task's Checkpoint lists the only paths to stage; commit only if the user has asked.
- Deploy order: API first (cPanel), then front (Vercel).

## Review Focus

1. Counter behind the invoices (counter 0, `E310000000001` ACEPTADO, the tenant-17 state before the 2026-11-20 fix): editing "Desde" or lowering "Hasta" must be refused using the invoices. → Task 1.
2. Adjacent ranges (E31 1–10 and 11–20): raising the first "Hasta" to 11 or more, or lowering the second "Desde" to 10 or less, is refused with a message that names the neighbor's number. → Tasks 1 and 4.
3. Odd body values (`numero_hasta` `0`, `-5`, `10.5`, `"abc"`, `""`; `fecha_vencimiento` `"2027-02-30"` or `"31/12/2027"`; `no_autorizacion` of 21 characters; a JSON array instead of an object; `{}`): Spanish 422 (200 unchanged for `{}`), never a PHP warning or a MySQL error. → Tasks 1 and 2.
4. A `vencido` or `agotado` range can still be edited (fix a past-date typo, raise "Hasta"). → Task 1.
5. `formatApiDate('2027-12-31')` shows the 31st with the host at UTC−4, UTC and UTC+13, and the edit form compares and pre-fills the raw `2027-12-31`. → Tasks 3 and 4.

## File Structure

| File | Change | Responsibility |
|---|---|---|
| `api-gratex/src/Models/ncfModel.php` | modify | `updateRange` (+ `require_once` of `EstadosEcf.php`) |
| `api-gratex/src/Controllers/ncfController.php` | modify | `PUT /api/ncf/rangos/{id}` branch + audit |
| `api-gratex/tools/test_write_ahead_puro.php` | modify | new section 20 (model + controller + docs checks) |
| `api-gratex/docs/api/facturas.md`, `docs/database/schema.md` | modify | endpoint docs |
| `fiscalo/src/api/fechas.ts` | create | `formatApiDate`, no imports |
| `fiscalo/src/api/mappers.ts` | modify | re-export `formatApiDate` from `fechas.ts` |
| `fiscalo/src/api/http.ts`, `ncf.ts`, `types.ts`, `index.ts` | modify | `putJson`, `updateNcfRango`, `UpdateRangoInput` |
| `fiscalo/src/features/ecf/rangosNcf.ts` | modify | `problemaConEdicion`, `cambiosDelFormulario` |
| `fiscalo/src/features/ecf/RangosNcfModal.tsx` | modify | ✎ per row, edit form |
| `fiscalo/scripts/test-fechas-api.ts` | create | Node test for `formatApiDate` |
| `fiscalo/scripts/test-rangos-ncf.ts` | modify | tests for the two new rules functions |

---

### Task 1: `ncfModel::updateRange`

**Files:**
- Modify: `src/Models/ncfModel.php` (add after `registerRange`; add `require_once __DIR__ . '/../Utils/FacturacionElectronica/EstadosEcf.php';` at the top)
- Test: `tools/test_write_ahead_puro.php` (new section 20 above the closing `wa_fin()` block)

**Interfaces:**
- Consumes: `EstadosEcf::sql(EstadosEcf::REINTENTABLES)`, `ncfModel::listRanges(?string $ambiente, ?string $type): array`, `ncfModel::ambienteLabel` (private static), `GuionConexion`/`puro_pdo_error` from the same test file.
- Produces: `public function updateRange(int $id, array $cambios, ?string $ambiente = null): array`, always one of:
  - `['success', ['rango' => array, 'cambios' => array<string, array{antes: int|string|null, despues: int|string|null}>]]`. `rango` is the row of `listRanges($amb, $type)` with that id, read after commit (fall back to the locked row with the changes merged if absent); `cambios` is `[]` when nothing differs.
  - `['notfound', string]` · `['error', string]` (Spanish).

The statements `updateRange` issues, in order. The test scripts them by these fragments, so keep them distinct. Use only `prepare/execute/fetchAll/fetchColumn/rowCount/beginTransaction/commit/rollBack` (all the fake implements):
1. Validate `$cambios` first: no database call on bad input.
2. `SELECT type FROM ncf_sequences WHERE id = :id AND ambiente = :ambiente AND type LIKE 'E%'` → `fetchColumn()`; false → `notfound`.
3. `beginTransaction()`, then `SELECT id, type, current_value, numero_desde, numero_hasta, fecha_vencimiento, no_solicitud, no_autorizacion FROM ncf_sequences WHERE type = :type AND ambiente = :ambiente ORDER BY numero_desde FOR UPDATE` (note `ORDER BY numero_desde` alone).
4. Only when `numero_desde` or `numero_hasta` is among the changes: `SELECT MAX(CAST(SUBSTRING(e_ncf, 4) AS UNSIGNED)) FROM facturas WHERE e_ncf LIKE :patron AND (ambiente_dgii = :ambiente OR ambiente_dgii IS NULL) AND estado_dgii NOT IN (…EstadosEcf::sql(EstadosEcf::REINTENTABLES)…) AND CAST(SUBSTRING(e_ncf, 4) AS UNSIGNED) >= :desde`, plus `AND … < :limite` when a next range exists (`:patron` = `E31%`, `:desde` = the range's current "Desde", `:limite` = next range's "Desde").
5. If anything really changes: `UPDATE ncf_sequences SET <col> = :<col>, … WHERE id = :id`, columns from the fixed allowlist, params named after the columns; `current_value = :current_value` (new "Desde" − 1) is added when "Desde" changes. Then `commit()`, then `listRanges`.
6. On `PDOException`: `rollBack()` if in a transaction; `errorInfo[0] === '23000'` (the `uq_type_amb_desde` key) → error naming the "Desde"; anything else → `error_log('[ncfModel] updateRange …')` and a generic Spanish error.

- [ ] **Step 1: Write the failing tests.** Add section 20 with this guard and helper (same style as section 19):

```php
echo "\n== 20) ncfModel::updateRange y PUT /api/ncf/rangos/{id} ==\n";
$s20Existe = method_exists(ncfModel::class, 'updateRange');
wa_chk('20: ncfModel::updateRange(int $id, array $cambios, ?string $ambiente = null): array es publico',
    $s20Existe && (new ReflectionMethod(ncfModel::class, 'updateRange'))->isPublic());
if ($s20Existe) {
    $s20Rango = static fn(array $r = []): array => $r + ['id' => 15, 'type' => 'E31', 'prefix' => 'E31', 'description' => 'Rango autorizado DGII',
        'ambiente' => 'ecf', 'current_value' => 0, 'numero_desde' => 1, 'numero_hasta' => 10, 'fecha_vencimiento' => '2027-12-21',
        'no_solicitud' => null, 'no_autorizacion' => null, 'created_at' => '2026-11-12 09:57:55', 'updated_at' => '2026-11-12 09:57:55'];
    $s20Correr = static function (array $rangos, array $cambios, int $id = 15, ?int $maxFacturas = null, bool $existe = true): array {
        $c = new GuionConexion();
        $c->filas['SELECT type FROM ncf_sequences'] = $existe ? [['type' => 'E31']] : [];
        $c->filas['FOR UPDATE'] = $rangos;
        $c->filas['FROM facturas'] = $maxFacturas === null ? [] : [['mx' => $maxFacturas]];
        $c->filas['ORDER BY type, numero_desde'] = $rangos; // listRanges tras el commit
        $ref = new ReflectionClass(ncfModel::class);
        $m = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('conexion')->setValue($m, $c);
        return [$m->updateRange($id, $cambios, 'ecf'), $c];
    };
    $s20B = $s20Rango(['id' => 16, 'numero_desde' => 11, 'numero_hasta' => 20, 'current_value' => 10]); // rango siguiente, sin usar
```

Then the scenario table `[descripcion, rangos, cambios, id, maxFacturas, esperado, fragmento del mensaje|null, ¿hay UPDATE?]` and its loop:

```php
    $s20Casos = [
        ['la fecha 2027-12-21 se corrige a 2027-12-31', [$s20Rango()], ['fecha_vencimiento' => '2027-12-31'], 15, null, 'success', null, true],
        ['fecha inexistente 2027-02-30', [$s20Rango()], ['fecha_vencimiento' => '2027-02-30'], 15, null, 'error', 'fecha', false],
        ['fecha en d/m/Y', [$s20Rango()], ['fecha_vencimiento' => '31/12/2027'], 15, null, 'error', 'fecha', false],
        ['autorizacion de 21 caracteres', [$s20Rango()], ['no_autorizacion' => str_repeat('9', 21)], 15, null, 'error', '20', false],
        ['autorizacion de 20 caracteres', [$s20Rango()], ['no_autorizacion' => str_repeat('9', 20)], 15, null, 'success', null, true],
        ['Hasta 10 -> 100', [$s20Rango()], ['numero_hasta' => 100], 15, null, 'success', null, true],
        ['Hasta bajo el contador (5): nombra el 5', [$s20Rango(['current_value' => 5])], ['numero_hasta' => 3], 15, null, 'error', '5', false],
        ['Hasta bajo lo facturado (contador 0, factura #7): nombra el 7', [$s20Rango()], ['numero_hasta' => 5], 15, 7, 'error', '7', false],
        ['Hasta que invade el rango 11-20: nombra el 11', [$s20Rango(), $s20B], ['numero_hasta' => 11], 15, null, 'error', '11', false],
        ['Hasta menor que Desde', [$s20Rango()], ['numero_desde' => 8, 'numero_hasta' => 7], 15, null, 'error', null, false],
        ['Desde con usados (contador 7): nombra el 7', [$s20Rango(['current_value' => 7])], ['numero_desde' => 2], 15, null, 'error', '7', false],
        ['Desde con contador atrasado y factura #4: nombra el 4', [$s20Rango()], ['numero_desde' => 6], 15, 4, 'error', '4', false],
        ['Desde sin usar 1 -> 5', [$s20Rango()], ['numero_desde' => 5], 15, null, 'success', null, true],
        ['Desde mayor que Hasta: nombra el 10', [$s20Rango()], ['numero_desde' => 11], 15, null, 'error', '10', false],
        ['Desde que pisa el rango anterior (1-10): nombra el 10', [$s20Rango(['current_value' => 10]), $s20B], ['numero_desde' => 10], 16, null, 'error', '10', false],
        ['fila sin limite: manda a Registrar', [$s20Rango(['numero_hasta' => null])], ['numero_hasta' => 50], 15, null, 'error', 'Registrar', false],
        ['cambios vacios: 200 sin UPDATE', [$s20Rango()], [], 15, null, 'success', null, false],
        ['mismo valor que el guardado: sin UPDATE', [$s20Rango()], ['fecha_vencimiento' => '2027-12-21'], 15, null, 'success', null, false],
        ['un rango vencido se puede editar', [$s20Rango(['fecha_vencimiento' => '2020-01-01'])], ['fecha_vencimiento' => '2027-12-31'], 15, null, 'success', null, true],
        ['un rango agotado se puede ampliar', [$s20Rango(['current_value' => 10])], ['numero_hasta' => 100], 15, null, 'success', null, true],
    ];
    foreach ($s20Casos as [$desc, $rangos, $cambios, $id, $maxFact, $esperado, $contiene, $hayUpdate]) {
        [$r, $c] = $s20Correr($rangos, $cambios, $id, $maxFact);
        wa_chk("20: {$desc}", $r[0] === $esperado && ($contiene === null || str_contains(is_string($r[1]) ? $r[1] : '', $contiene))
            && ($c->sql('UPDATE ncf_sequences') !== null) === $hayUpdate, $r);
    }
```

And these specific checks (assertions pinned; write each as one `wa_chk`):
- `numero_hasta` and `numero_desde` set to each of `0, -5, 10.5, 'abc', ''` → `error` and `$c->llamadas === []` (no query at all). `type`, `ambiente`, `current_value` as keys → `error`, no query.
- Unknown id (`$s20Correr([$s20Rango()], ['numero_hasta' => 50], 99, null, false)`) → `notfound` and `$c->de('beginTransaction') === []`.
- Successful Hasta edit: order of `$c->llamadas` is `beginTransaction` < prepare containing `FOR UPDATE` < prepare containing `UPDATE ncf_sequences` < `commit`; the result's `cambios === ['numero_hasta' => ['antes' => 10, 'despues' => 100]]`; the UPDATE params contain `:numero_hasta => 100, :id => 15`; the `FROM facturas` statement contains `NOT IN ('RECHAZADO'` and its params `:patron => 'E31%'`.
- A date-only edit never reads `facturas` (`$c->sql('FROM facturas') === null`).
- Desde edit (1→5): UPDATE params contain `:numero_desde => 5` and `:current_value => 4`.
- Duplicate key: `$c->errores[] = ['UPDATE ncf_sequences', puro_pdo_error(1062, '23000', 'Duplicate entry')]` on a Desde edit → `error` naming `5`, calls include `rollBack`, none `commit`.
- Any other `PDOException` at the `FOR UPDATE` read → `error`, `rollBack` called, log (use `puro_con_log`) contains `[ncfModel] updateRange`.

- [ ] **Step 2: Run it and see it fail.** Run: `php tools/test_write_ahead_puro.php | sed -n '/== 20)/,$p'`. Expected: one `[FALLO]` for `updateRange` (the guard skips the rest); sections 1–19 still `[OK  ]`.
- [ ] **Step 3: Implement `updateRange`** per the statement list above and spec §4.2 rules 1–5. Decisions: compare normalized values (ints; strings trimmed; `''` → `null` for the two `no_*` fields) so an unchanged value is not a change; the effective "Desde"/"Hasta" after the edit drive every check; "last used" = `max((int) current_value, (int) MAX-from-facturas)`; the previous range is the one with the nearest smaller `numero_desde`, the next the nearest larger (a legacy row with `numero_hasta` NULL counts by its `current_value` as previous and by its `numero_desde` as next); build `SET` only from the column allowlist.
- [ ] **Step 4: Run the suite.** Run: `php -l src/Models/ncfModel.php && php tools/test_write_ahead_puro.php | tail -3`. Expected: `No syntax errors detected` and `N de N pruebas OK`, no `FALLO`.
- [ ] **Step 4b: Validate the real SQL read-only** (the fake can't parse SQL). Run statement 2, the lock read without `FOR UPDATE` and statement 4 through `php -d extension=pdo_mysql tools/produccion-lectura/sql.php --db=smhynzte_005 --sql="…"` with real values (`E31`, `ecf`, range id 15, `:desde` 1). Expected: statement 4 returns `1` (the accepted `E310000000001`), the others return the E31 rows, no MySQL syntax error. Never use `--ejecutar`; the `UPDATE` is checked only by reading it.
- [ ] **Step 5: Checkpoint.** Paths: `src/Models/ncfModel.php`, `tools/test_write_ahead_puro.php`.

---

### Task 2: `PUT /api/ncf/rangos/{id}` and its docs

**Files:**
- Modify: `src/Controllers/ncfController.php` (the `case 'PUT':` branch), `docs/api/facturas.md`, `docs/database/schema.md`
- Test: `tools/test_write_ahead_puro.php` (section 20, after Task 1's checks)

**Interfaces:**
- Consumes: `ncfModel::updateRange` (Task 1), `InputSanitizer::jsonInput(false)`, `AuditLogger::log`.
- Produces: `PUT /api/ncf/rangos/{id}` with a partial JSON body → `{"status": true, "data": <range, GET shape>}`; errors `{"status": false, "error": "<texto>"}` with `404` / `422` / `400`.

- [ ] **Step 1: Write the failing checks** (controller read as text with `\r\n` → `\n`, like section 19):
  - Inside `case 'PUT':`, the `'/api/ncf/rangos'` check comes before `'/api/ncf/sequence'` and before the legacy `Update Invoice NCF` code.
  - The id is read with `preg_match('#/api/ncf/rangos/(\d+)(?:[/?]|$)#', $uri, $m)` (assert that exact string); a PUT without a valid id answers `404`.
  - The file contains `->updateRange(`, `'action' => 'NCF_RANGE_UPDATE'`, `'entity_type' => 'ncf_rango'`; `'notfound'` maps to 404 and `'error'` to 422; the audit call sits under a condition on `$result[1]['cambios'] !== []`.
  - A body that is not an object answers `400`: the text `No se pudieron leer los datos del rango` appears twice in the file (POST and PUT).
  - `docs/api/facturas.md` contains `PUT /api/ncf/rangos/{id}` and `NCF_RANGE_UPDATE`; its endpoint table row for `/api/ncf/rangos` lists `GET/POST/PUT`; `docs/database/schema.md` mentions `PUT /api/ncf/rangos/{id}`.
- [ ] **Step 2: Run it and see it fail** (same command as Task 1 Step 2; expected: the new checks `[FALLO]`).
- [ ] **Step 3: Implement the PUT branch** at the top of `case 'PUT':`. Behavior: URI without a numeric id → 404 `No encontramos ese rango.`; body not an object (`!is_object`) → 400 with the POST branch's text (`No se pudieron leer los datos del rango. Inténtalo de nuevo.`); `$cambios = (array) $body`; `success` → audit only when `cambios` is non-empty (`old_values` = the `antes` values, `new_values` = the `despues` values plus `rango_id`, `entity_id` = the range's type, description `Rango NCF autorizado por DGII corregido.`) and answer `data` = `$result[1]['rango']`; `notfound` → 404; `error` → 422. Then `break` so the legacy branches are untouched.
- [ ] **Step 3b: Document it** in `docs/api/facturas.md` (table row, and a `**PUT /api/ncf/rangos/{id}**` block after the POST one: body fields, the five rules in one line each, the response and error codes, the audit action) and the one-line mention in `docs/database/schema.md`.
- [ ] **Step 4: Run.** `php -l src/Controllers/ncfController.php && php tools/test_write_ahead_puro.php | tail -3`. Expected: all `OK`.
- [ ] **Step 5: Checkpoint.** Paths: `src/Controllers/ncfController.php`, `docs/api/facturas.md`, `docs/database/schema.md`, `tools/test_write_ahead_puro.php`.

---

### Task 3: `formatApiDate` stops shifting date-only values (`fiscalo`)

**Files:**
- Create: `src/api/fechas.ts`, `scripts/test-fechas-api.ts`
- Modify: `src/api/mappers.ts`

**Interfaces:**
- Produces: `export function formatApiDate(value?: string | null): string` in `src/api/fechas.ts` (no imports), unchanged signature; `mappers.ts` re-exports it (`export { formatApiDate } from './fechas'`, plus an `import` if `mappers.ts` itself uses it), so `src/api/index.ts` and its 29 call sites don't change.

- [ ] **Step 0: Set up.** Ask for access to `C:\Users\Signos\Documents\edwin\fiscalo`, then create branch `feat/rangos-edicion` from `main` (the repo keeps worktrees in `.claude/worktrees/`; use superpowers:using-git-worktrees). Record the baseline: `npm run typecheck` and `npm run lint`.
- [ ] **Step 1: Write the failing test** `scripts/test-fechas-api.ts` (header like `test-rangos-ncf.ts`; same `chk` helper; `dia = (s) => Number(/\d+/.exec(s)?.[0])`):
  - `dia(formatApiDate('2027-12-31')) === 31` and the text contains `dic`; `dia(formatApiDate('2027-01-01')) === 1`.
  - `dia(formatApiDate('2026-11-20 14:31:22')) === 20`; `dia(formatApiDate('31-12-2027')) === 31`.
  - `formatApiDate('abc') === 'abc'`; `formatApiDate(null) === '—'`; `formatApiDate('') === '—'`.
  - For `TZ` in `America/Santo_Domingo`, `UTC`, `Pacific/Auckland`: run `execFileSync(process.execPath, ['--input-type=module', '-e', "import(<file URL of src/api/fechas.ts>).then(m => console.log(m.formatApiDate('2027-12-31')))"], { env: { ...process.env, TZ: tz } })` and check `dia(output) === 31`.
- [ ] **Step 2: Move the function unchanged and see it fail.** Create `fechas.ts` with `formatApiDate` copied as is, replace its definition in `mappers.ts` by the re-export, run `node scripts/test-fechas-api.ts`. Expected: the date-only checks fail (`31` comes out as `30`) with the host in a negative UTC offset (the user's PC is UTC−4); on a UTC host they pass by luck, so run it on that PC.
- [ ] **Step 3: Fix.** In `fechas.ts`, add a branch for `^\d{4}-\d{2}-\d{2}$` that builds `new Date(y, m - 1, d)` (a local date, the way the existing `dd-mm-yyyy` branch does).
- [ ] **Step 4: Run.** `node scripts/test-fechas-api.ts` (all `OK`), `npm run typecheck`, `npm run lint` (no new problems against the baseline).
- [ ] **Step 5: Review the other call sites.** `grep -rn formatApiDate src | grep -v "\.claude"` and look at what each passes; only date-only values change (they were wrong). List any surprise for the user instead of fixing it here.
- [ ] **Step 6: Checkpoint.** Paths: `src/api/fechas.ts`, `src/api/mappers.ts`, `scripts/test-fechas-api.ts`.

---

### Task 4: client, types and pure rules (`fiscalo`)

**Files:**
- Modify: `src/api/http.ts`, `src/api/ncf.ts`, `src/api/types.ts`, `src/api/index.ts`, `src/features/ecf/rangosNcf.ts`
- Test: `scripts/test-rangos-ncf.ts`

**Interfaces:**
- Produces, in `http.ts`: `export function putJson<T>(path: string, payload: unknown): Promise<T>` (the twin of `postJson` with `method: 'PUT'`).
- In `types.ts`: `export interface UpdateRangoInput { fecha_vencimiento?: string; numero_desde?: number; numero_hasta?: number; no_solicitud?: string; no_autorizacion?: string }` (empty string clears a `no_*` field).
- In `ncf.ts` (re-exported from `index.ts`): `export function updateNcfRango(id: number, input: UpdateRangoInput): Promise<NcfRango>` → `putJson(\`/api/ncf/rangos/${id}\`, input)`.
- In `rangosNcf.ts`: `export interface FormularioEdicion { fecha_vencimiento: string; numero_desde: string; numero_hasta: string; no_solicitud: string; no_autorizacion: string }`; `export function cambiosDelFormulario(rango: NcfRango, form: FormularioEdicion): UpdateRangoInput` (only changed fields; the date compared as the raw string with `rango.fecha_vencimiento ?? ''`; `numero_desde` never included when `Number(rango.usados ?? 0) > 0`; `no_*` compared trimmed against `rango.no_* ?? ''`; `{}` when nothing changed); `export function problemaConEdicion(rangos: NcfRango[], rango: NcfRango, cambios: UpdateRangoInput): string | null` (spec rules 4 and 5 over the loaded ranges, counter only, no invoices; non-integer or < 1 numbers; previous range = same type, nearest smaller `numero_desde`; next = nearest larger).

- [ ] **Step 1: Write the failing tests** in `scripts/test-rangos-ncf.ts` (new `console.log('Editar un rango')` block, reusing `rango`, `chk`, `igual`), with `r1 = E31 1–10, current_value 0, usados 0, fecha '2027-12-31'` and `r2 = E31 11–20, current_value 10, usados 0`:
  - `problemaConEdicion([r1], r1, { numero_hasta: 100 }) === null`; `([r1, r2], r1, { numero_hasta: 11 })` is a string containing `11`; `([r1, r2], r2, { numero_desde: 10 })` is a string containing `10`.
  - `([r1], { ...r1, current_value: 5 }, { numero_hasta: 3 })` is a string containing `5`; `([r1], { ...r1, current_value: 3, usados: 3 }, { numero_desde: 2 })` is a string (Desde locked); `([r1], r1, { numero_hasta: 10.5 })`, `{ numero_hasta: 0 }` and `{ numero_desde: 11 }` are strings.
  - `cambiosDelFormulario(r1, form igual al guardado)` equals `{}` (the date form field is the raw `'2027-12-31'`); changing only the date gives `{ fecha_vencimiento: '2027-12-21' }`; with `usados: 3`, a changed Desde is not included; an emptied `no_autorizacion` that had a value gives `{ no_autorizacion: '' }`.
- [ ] **Step 2: Run it and see it fail.** `node scripts/test-rangos-ncf.ts`. Expected: fails because the two functions don't exist.
- [ ] **Step 3: Implement** `putJson`, `UpdateRangoInput`, `updateNcfRango` (+ export in `index.ts` next to `registerNcfRango`), `FormularioEdicion`, `cambiosDelFormulario`, `problemaConEdicion` with the messages in the tone of `problemaConExistentes`.
- [ ] **Step 4: Run.** `node scripts/test-rangos-ncf.ts` (all `OK`, including the earlier register checks), `npm run typecheck`, `npm run lint`.
- [ ] **Step 5: Checkpoint.** Paths: the five modified files and `scripts/test-rangos-ncf.ts`.

---

### Task 5: edit form in the modal (`fiscalo`)

**Files:**
- Modify: `src/features/ecf/RangosNcfModal.tsx`; `docs/specs/ncf-rangos-edicion.md` (in `api-gratex`: `Estado` → `Implementado · <fecha>`)

**Interfaces:**
- Consumes: `updateNcfRango`, `cambiosDelFormulario`, `problemaConEdicion`, `FormularioEdicion` (Task 4), `ApiError`, the existing `Modal`, `Btn`, `Icon`.
- Produces: no new exports.

- [ ] **Step 1: Implement the UI.** In the table, a last column with a `Btn variant="ghost" size="sm" icon="edit-3"` (`title` and `aria-label` «Editar rango») on rows where `r.numero_hasta != null`. State `editando: NcfRango | null` and `form: FormularioEdicion`. Opening it fills the form from the raw API strings (`fecha_vencimiento` as returned, `String(numero_desde)`, `no_solicitud ?? ''`…), closes the register form and clears `error`. The edit card reuses the register card's markup: «Tipo» read-only text; «Desde» `disabled` with the note «Ya hay N usados» when `Number(usados) > 0`; help line under the date: «Es la fecha que se envía a la DGII: debe coincidir con tu autorización.»; buttons «Cancelar» and «Guardar cambios» (disabled while `saving` or when `cambiosDelFormulario` is `{}`). Saving: `problemaConEdicion` first (show its text), then `updateNcfRango(editando.id, cambios)`; success → `toast.success('Rango E31 actualizado.')` (with the range's type), close the form; failure → `ApiError` message in the same error line as the register form.
- [ ] **Step 2: Share the refresh.** Move the four `invalidateQueries` calls of the register flow into one local `refrescar()` and call it from both saves (DRY; same keys: `['ncf']`, `['facturas','stats']`, `['facturas','avisos']`, `['gastos','stats']`).
- [ ] **Step 3: Verify.** `npm run typecheck`, `npm run lint`, and rerun `node scripts/test-rangos-ncf.ts` and `node scripts/test-fechas-api.ts`. Expected: no new problems against the Task 3 baseline. Do not start a dev server.
- [ ] **Step 4: Hand the user a smoke checklist** (not run here): deploy the API first; open «Rangos e-NCF autorizados»; the E31 row shows 31 dic 2027; ✎ opens the form with `2027-12-31`; change the date, save, see the toast and the table update; the "Desde" field is locked on a range with usados; E32/E33 behave the same; «Sin límite» rows have no ✎.
- [ ] **Step 5: Mark the spec** `Estado: Implementado · <fecha>` in `docs/specs/ncf-rangos-edicion.md`.
- [ ] **Step 6: Checkpoint.** Paths in `fiscalo`: `src/features/ecf/RangosNcfModal.tsx`; in `api-gratex`: `docs/specs/ncf-rangos-edicion.md`.
