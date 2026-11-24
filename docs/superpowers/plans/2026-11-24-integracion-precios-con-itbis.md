# Precios con ITBIS en integración — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `POST /api/integracion/ecf` honors `indicador_monto_gravado = 1` (prices and discounts with ITBIS included) for E31, E32, E33 and E34, so an integrator keeps quantities and totals without rounding discounts; any other use of the flag fails clearly before anything is signed.

**Architecture:** The integration branch of `ECFEmissionService::emitir` (items, totals, indicator) moves unchanged into a public static `prepararItemsIntegracion(array $payload): array`, which `emitir` calls. Task 2 teaches that function to switch to the already-validated included-ITBIS calculation (`EcfItemMapper::map/totales(..., true)`) when the flag is `1` in a non-strict request. Task 3 writes the integrator guide.

**Tech Stack:** PHP 8.3, no Composer; tests are pure PHP CLI scripts (no database, network or server).

**Spec:** `docs/specs/integracion-precios-con-itbis.md` (read it first; this plan does not repeat its rules).

## Global Constraints

- No migration, no new dependency, no new endpoint or field name: the switch is `indicador_monto_gravado` (spec §4.1). Only `src/Utils/FacturacionElectronica/ECFEmissionService.php` changes under `src/`.
- Activation: non-strict (`strict_input` empty) AND `is_scalar($flag) && (string) $flag === '1'`, i.e. exactly when `ECFXmlBuilder` would write `1` into `<IndicadorMontoGravado>`. Anything else (`0`, `"0"`, `""`, null, absent, `strict_input`) behaves exactly as today. Reason: a flag that renders as `1` with net-price math is the 1934 trap (spec §1).
- Types admitted in the mode: `EcfItemMapper::TIPOS_PRECIOS_CON_ITBIS` (`31`, `32`, `33`, `34`). Any other type with the mode active throws `EcfUsuarioException` before anything is signed or sent (HTTP 502 through `integracionEcfController`).
- Integrators receive `getMessage()` (the technical text, paths and config names masked by `EcfUsuarioException::mensajePublico`), so that text is a complete Spanish sentence and contains no `/` between tokens (`E31/E32` style) that could be masked as a path.
- No new math: lines and totals come from `EcfItemMapper::map($items, false, true)` and `totales($items, true)`. Do not touch `desglosarIncluido`.
- `api-gratex`: PHP 8.3 syntax only; files are CRLF (the Edit and Write tools keep it; check with `grep -c $'\r$'`); code comments in ASCII Spanish like the surrounding code; docs with accents.
- No server, mock backend or browser (the user runs those): verify with `php -l` and the pure CLI suites only.
- Git: never `git add -A` or `git add .` (the tree carries unrelated uncommitted work, among it `docs/api/referencia-endpoints-integracion.md`, `docs/api/guia-cliente-integracion.md`, `docs/README.md`); each task's Checkpoint lists the only paths to stage; **commit only if the user has asked**.
- Deploy: PHP only. Nothing changes for a tenant until it sends the flag as `1`.

## Review Focus

1. `indicador_monto_gravado` as JSON `true` or `1.0` must not reach the XML as `1` with net-price math: it activates like `1`. → Task 2.
2. `0`, `"0"`, `""`, `null` and absent: calculation and flag exactly as today (200 × 16.50 still gives 3,894.00 and the flag passes through untouched). → Tasks 1 and 2.
3. Mixed rates and exempt lines in one invoice with the flag (18 %, 16 %, 0 %, exempt): per-rate totals, total = Σ MontoItem. → Task 2.
4. Certification set (`strict_input`) with the flag `1`, even on a type outside E31–E34: no error, flag passed through as it comes, net-price math, totals only from the payload override. → Task 2.
5. A quantity with 3 decimals or a price with 5 decimals in the mode: rounded to 2 / 4 before calculating, so `MontoItem` matches what the XML prints (as app and POS do). Also: the client's `totales` override keeps winning key by key, and an empty `items` gives zero totals with no warning. → Task 2.

## File Structure

| File | Change | Responsibility |
|---|---|---|
| `src/Utils/FacturacionElectronica/ECFEmissionService.php` | modify | `prepararItemsIntegracion` (new, public static); `emitir()` calls it; activation and type check in Task 2 |
| `tools/test_precios_itbis_incluido.php` | modify | `require_once` of `ECFEmissionService.php`; new sections 7 and 8 |
| `docs/api/integracion-precios-con-itbis.md` | create | integrator guide with the JSON and XML example |
| `docs/integrations/dgii-ecf.md` | modify | note: the contract and the 1934 precedent |
| `docs/specs/integracion-precios-con-itbis.md` | modify | status → Implemented |

---

### Task 1: Move the integration branch into `ECFEmissionService::prepararItemsIntegracion` (no behavior change)

**Files:**
- Modify: `src/Utils/FacturacionElectronica/ECFEmissionService.php` (the block at lines 294-317 of `emitir()`)
- Test: `tools/test_precios_itbis_incluido.php` (new section 7 after section 6; `require_once` of `ECFEmissionService.php` next to the existing ones at the top)

**Interfaces:**
- Produces: `public static function prepararItemsIntegracion(array $payload): array`. Reads `items`, `totales` and `strict_input` of an integration payload (Task 2 adds `tipo_ecf` and `indicador_monto_gravado`) and returns the same payload with `items` mapped by `EcfItemMapper::map` and `totales` resolved: no override → calculated; override with `strict_input` → the override alone; override otherwise → `array_merge(calculated, override)`; null and `''` values are dropped from the override first.

- [ ] **Step 1: Write the failing characterization tests** — section `7. Integración: la rama de emitir() sin el indicador`, using `$linea` and `$igual` from the top of the file:

  ```php
  $prep = static fn(array $p): array => ECFEmissionService::prepararItemsIntegracion($p);
  $base = ['tipo_ecf' => '31', 'items' => [$linea(1, 200, 13.9831, 0.01)]];

  $r = $prep($base);
  $chk('Sin indicador: totales = map + totales sobre el neto, como siempre',
      $r['totales'] === EcfItemMapper::totales(EcfItemMapper::map($base['items'], false)), $r['totales']);
  $chk('Sin indicador: MontoItem 2796.61 y MontoTotal 3300.00 (la linea con descuento de redondeo)',
      $igual($r['items'][0]['monto_item'], 2796.61) && $igual($r['totales']['monto_total'], 3300.00), $r);
  $chk('Sin indicador: no se agrega indicador_monto_gravado', !array_key_exists('indicador_monto_gravado', $r));
  $chk('Indicador "0": queda tal cual', $prep($base + ['indicador_monto_gravado' => '0'])['indicador_monto_gravado'] === '0');
  $r = $prep($base + ['totales' => ['monto_total' => 999.99, 'total_itbis' => null]]);
  $chk('Override del cliente gana clave por clave; null no cuenta',
      $r['totales']['monto_total'] === 999.99 && $igual($r['totales']['total_itbis'], 503.39), $r['totales']);
  $r = $prep($base + ['strict_input' => true, 'totales' => ['monto_total' => 999.99]]);
  $chk('Estricto con override: solo el override', $r['totales'] === ['monto_total' => 999.99], $r['totales']);
  $r = $prep(['tipo_ecf' => '31', 'items' => []]);
  $chk('Sin items: totales en cero y sin error', $r['items'] === [] && $r['totales']['monto_total'] === 0.0, $r);
  ```

- [ ] **Step 2: Run to verify it fails** — `php tools/test_precios_itbis_incluido.php`. Expected: fatal `Call to undefined method ECFEmissionService::prepararItemsIntegracion()` and a non-zero exit.

- [ ] **Step 3: Implement `prepararItemsIntegracion`** in `ECFEmissionService.php`: move lines 294-317 of `emitir()` verbatim, comments included, into the new method, and replace them in `emitir()` with `$payload = self::prepararItemsIntegracion($payload);` (the `$emisor` and `$ambienteEarly` lines above stay). Put the method next to the other public static helper, `codigoSeguridadDeXml`.

- [ ] **Step 4: Run to verify it passes** — `php -l src/Utils/FacturacionElectronica/ECFEmissionService.php` reports no errors, and `php tools/test_precios_itbis_incluido.php` ends with `TODO OK (52 verificaciones)` (45 existing + 7 new).

- [ ] **Step 5: Regression** — `php tools/express_baseline.php --comparar --suites=test_precios_itbis_incluido,test_integracion_xml,test_integracion_alcance,test_write_ahead_puro,test_ecf_montos_descuento`. Expected: no `DIFERENCIA` except `test_write_ahead_puro.php: total 379 -> 459` (stale baseline since commit `2bdf038`, not from this work), and `AVISO ... mejoró (recapturar)` for the suites that grew.

**Checkpoint:** stage only `src/Utils/FacturacionElectronica/ECFEmissionService.php` and `tools/test_precios_itbis_incluido.php`.

---

### Task 2: Activate the included-ITBIS mode for `indicador_monto_gravado = 1`

**Files:**
- Modify: `src/Utils/FacturacionElectronica/ECFEmissionService.php` (`prepararItemsIntegracion`)
- Test: `tools/test_precios_itbis_incluido.php` (new section 8)

**Interfaces:**
- Consumes: `prepararItemsIntegracion` from Task 1; `EcfItemMapper::normalizarCantidadPrecio(array): array`, `EcfItemMapper::map(array, bool $strict, bool $preciosConItbis): array`, `EcfItemMapper::totales(array, bool $preciosConItbis): array`, `EcfItemMapper::TIPOS_PRECIOS_CON_ITBIS`; `new EcfUsuarioException(string $mensajeTecnico, string $mensajeUsuario)`.
- Produces: the same signature. With the mode active the returned payload has `indicador_monto_gravado === '1'`, items mapped with `preciosConItbis = true` after `normalizarCantidadPrecio`, and totals from `totales(..., true)` merged with the override exactly as in Task 1.

- [ ] **Step 1: Write the failing tests** — section `8. Integración con indicador_monto_gravado = 1`:

  ```php
  $con = static fn(string $tipo, array $items, $flag = 1, array $extra = []): array =>
      ECFEmissionService::prepararItemsIntegracion(['tipo_ecf' => $tipo, 'indicador_monto_gravado' => $flag, 'items' => $items] + $extra);
  $tipoFalla = static function (string $tipo) use ($con, $linea): ?string {
      try { $con($tipo, [$linea(1, 200, 16.50)]); return null; }
      catch (EcfUsuarioException $e) { return $e->getMessage(); }
  };
  ```

  One `$chk` per line, values from the spec:
  1. Vendor case `$con('31', [$linea(1, 200, 16.50)])`: `items[0]['monto_item']` 3300.00, `monto_gravado_i1` 2796.61, `total_itbis1` 503.39, `monto_total` 3300.00, `indicador_monto_gravado === '1'`.
  2. Invoice of 14 lines, `[cantidad, precio]` = `[[200,16.50],[200,16.50],[10,50],[5,50],[5,125],[1,120],[5,125],[25,7],[40,3],[1,580],[20,2],[1,150],[1,550],[1,350]]`: `monto_gravado_i1` 9055.08, `total_itbis1` 1629.92, `monto_total` 10685.00 equal to Σ `monto_item`, and no line carries `descuento_monto`.
  3. Real discount, `$linea(1, 200, 16.50, 100.00)`: `monto_item` 3200.00, `monto_gravado_i1` 2711.86, `total_itbis1` 488.14, `monto_total` 3200.00.
  4. Flag `'1'`, `true` and `1.0` each give `monto_total` 3300.00 and `indicador_monto_gravado === '1'`.
  5. Flag `0`, `'0'`, `''` and `null` each give `monto_total` 3894.00 (net math, as today) and return the flag untouched.
  6. Mixed rates, items `$e31` from section 1 of the file, through `$con('31', $e31)`: `monto_gravado_i1` 622.88, `monto_gravado_i2` 69129.31, `monto_gravado_i3` 770.00, `monto_exento` 1625.00, `total_itbis` 11172.81, `monto_total` 83320.00 (the DGII set values).
  7. Types: `'32'`, `'33'`, `'34'` do not throw and give `monto_total` 3300.00; `'41'`, `'43'`, `'44'`, `'45'`, `'46'`, `'47'` throw `EcfUsuarioException` whose message contains `indicador_monto_gravado` and `E31, E32, E33 y E34` and contains no `/`.
  8. Strict: `$con('45', [$linea(1, 200, 16.50)], 1, ['strict_input' => true])` does not throw, returns the flag as it came (`1`) and keeps net math (`monto_total` 3894.00).
  9. Override in the mode: `['totales' => ['total_itbis1' => 500.00]]` → `total_itbis1` 500.00 and `monto_total` 3300.00 (calculated).
  10. Decimals: `$linea(1, 1.126, 16.123456)` → `items[0]['cantidad']` 1.13, `precio_unitario` 16.1235, `monto_item` 18.22.
  11. Empty `items` with the flag: `items === []`, `monto_total === 0.0`, `indicador_monto_gravado === '1'`.

- [ ] **Step 2: Run to verify it fails** — `php tools/test_precios_itbis_incluido.php`. Expected failures: the vendor case gives `monto_total` 3894.00 and no `'1'`; the types 41–47 do not throw; strict and flag-0 checks already pass (they pin today's behavior).

- [ ] **Step 3: Implement the activation in `prepararItemsIntegracion`**:
  - `$strict = !empty($payload['strict_input'])`; `$flag = $payload['indicador_monto_gravado'] ?? null`; `$preciosConItbis = !$strict && is_scalar($flag) && (string) $flag === '1'`.
  - Active and `(string) ($payload['tipo_ecf'] ?? '')` not in `EcfItemMapper::TIPOS_PRECIOS_CON_ITBIS`: throw `new EcfUsuarioException('indicador_monto_gravado = 1 (precios con ITBIS incluido) solo se admite en E31, E32, E33 y E34; este comprobante es E' . $tipo . '. Quite el indicador y envíe los precios sin ITBIS.', 'Los precios con ITBIS incluido solo se admiten en facturas de crédito fiscal y de consumo, y en sus notas de débito y crédito.')`.
  - Active: run `EcfItemMapper::normalizarCantidadPrecio` on the items first, pass `$preciosConItbis` as the third argument of `map` and the second of `totales`, and finish with `$payload['indicador_monto_gravado'] = '1'`. Inactive: the Task 1 path, untouched.
  - One ASCII comment states the invariant: flag `1` in the XML if and only if the calculation includes the ITBIS.

- [ ] **Step 4: Run to verify it passes** — `php -l` on the file and `php tools/test_precios_itbis_incluido.php` ending `TODO OK`.

- [ ] **Step 5: Through the XML builder** — add checks to section 8 that build the XML from the function's output with the helpers of section 5: `$xmlEcf('31', [], ['items' => $r['items'], 'totales' => $r['totales'], 'indicador_monto_gravado' => $r['indicador_monto_gravado']])` (its `$extra` overrides the defaults) and `$campo($xml, $tag)`. The vendor case gives `IndicadorMontoGravado` `1`, `MontoItem` 3300.00, `MontoGravadoI1` 2796.61, `TotalITBIS1` 503.39 and `MontoTotal` 3300.00; the discount case gives `MontoSubDescuento` 100.00 and `MontoTotal` 3200.00; both validate with `$validarXsd($xml, "$xsdDir/e-CF 31 v.1.0.xsd")`.

- [ ] **Step 6: Regression** — the Task 1 `express_baseline --comparar` command plus `test_notas_vinculadas`, `test_ecf_tipo_ingresos` and `test_pos_scratch_puro` in `--suites`. Expected: no new `DIFERENCIA` (the stale `test_write_ahead_puro` line is the only one).

**Checkpoint:** stage only `src/Utils/FacturacionElectronica/ECFEmissionService.php` and `tools/test_precios_itbis_incluido.php`.

---

### Task 3: Integrator guide and notes

**Files:**
- Create: `docs/api/integracion-precios-con-itbis.md`
- Modify: `docs/integrations/dgii-ecf.md` (new `###` after the 2020 section), `docs/specs/integracion-precios-con-itbis.md` (status)

**Interfaces:** Consumes the behavior of Task 2. Produces no code.

- [ ] **Step 1: Write the guide** in Spanish, formal «usted» like `docs/api/integracion-notas-credito-debito.md`, no tenant names (use a generic item such as «Alambre THHN 12 negro»). Sections: *Cuándo usarlo* · *Cómo se envía* (`indicador_monto_gravado: 1`; `precio_unitario` and `descuento_monto` in pesos **with** ITBIS; price up to 4 decimals, quantity up to 2; types E31–E34) · *Qué calculamos* (`MontoItem`; gravado por tasa = `round(Σ MontoItem / 1.18, 2)`; ITBIS = difference; total = sum of the lines) · *Ejemplo*: the JSON line and the XML it produces for 200 × RD$16.50 (2,796.61 + 503.39 = 3,300.00) and for the RD$100.00 discount (2,711.86 + 488.14 = 3,200.00, with its `TablaSubDescuento`) · *Errores* (`502` for a type outside E31–E34, with the message) · *Qué no hacer* (send `1` with prices without ITBIS; keep sending rounding discounts) · *Límite* (a price with ITBIS that does not fit 4 decimals can leave a line one centavo off). Take every number and XML fragment from the output of `ECFXmlBuilder` run offline, not by hand.

- [ ] **Step 2: Add the note to `dgii-ecf.md`** — `### Precios con ITBIS incluido en integración (indicador_monto_gravado = 1)`: the trap (flag copied, net math), the 1934 precedent (E310000000011, empresa 002: price 950.00 + ITBIS 171.00 = 1,121.00, accepted conditionally), the rule now (activation, types, error), and pointers to the guide and to `php tools/test_precios_itbis_incluido.php`.

- [ ] **Step 3: Update the spec** — Estado → «Implementado · 2026-11-24 (falta desplegar)»; in §4.3 replace «Guía de cliente … con el JSON y el XML» by the created path.

- [ ] **Step 4: Verify** — `grep -c $'\r$'` equals the line count for each new or edited doc (CRLF); each number in the guide appears in the suite or in the offline output; `grep -i -E "agrovisa|ferreherramientas|q & f"` finds nothing in `docs/api/integracion-precios-con-itbis.md`.

**Checkpoint:** stage only the new guide, `docs/integrations/dgii-ecf.md`, `docs/specs/integracion-precios-con-itbis.md` and this plan. Do not stage `docs/api/referencia-endpoints-integracion.md`, `docs/api/guia-cliente-integracion.md` or `docs/README.md` (someone else's work in progress): tell the user a row for the field and a link to the guide still need adding there.

---

## Post-review amendments (2026-11-24)

The independent review of the whole work found three things the plan got wrong; they were fixed in one pass, each with its test written first (see the ledger's `Final:` lines). The tasks above stay as executed; where they disagree with this section, this section wins.

1. **Review Focus 2 ("the flag passes through untouched") no longer holds in normal emission.** `prepararItemsIntegracion` reads `indicador_monto_gravado` the way the XSD does (`xs:integer`, enumeration 0/1: `1`, `"1"`, `"01"`, `" 1"`, `"+1"`, `true` activate; `0`, `""`, `null`, `false`, `"00"` do not) and returns it resolved as `'1'` or `'0'`; any other value throws `EcfUsuarioException`. The XML is unchanged for every input that was valid before (the builder already wrote `0` for an absent flag). `strict_input` still passes it through untouched.
2. **Review Focus 5 ("rounded to 2 / 4 before calculating, as app and POS do") was wrong.** The app validates first and never rounds in silence. In the mode, more than 2 decimals of quantity or 4 of price is now an error naming the line; `normalizarCantidadPrecio` only cleans binary noise.
3. **The invariant "flag 1 iff the math includes ITBIS" holds in normal emission only.** `strict_input` is client-controllable on this endpoint (the certification runner sends it); that is by design and is documented in the spec (§5) and in `docs/integrations/dgii-ecf.md`.

Added: `tools/verificar_indicador_monto_gravado_integracion.sql`, a read-only check to run on the master before deploying (the spec's evidence is a 10-day log).
