# Checklist — Despliegue de Fiscalpoint Express (la API)

Checklist operativo para subir a producción la edición Express, en el orden en que hay
que ejecutarlo. Esta ola (plan 02, «entitlements») sale **apagada**: la API aprende a
leer `tenants.edicion`, pero mientras ninguna empresa esté en `express` nada cambia para
nadie. Para el «por qué» de cada pieza ver el diseño y el anexo de Express (repo `fiscalo`,
`docs/superpowers/specs/2026-10-27-fiscalpoint-express-*.md`, secciones 13.7 y 14); para
la migración, el encabezado de `db/master_migrations/015_tenants_edicion.sql`.

> **Las pruebas de la sección 1 corren contra las bases scratch o sin base, nunca contra
> producción.** Los pasos de la sección 2 son sobre producción y los hace el dueño del
> sistema, con un respaldo en la mano.

---

## 1. Pruebas en verde antes de subir

Se corren desde la raíz del API, en este orden. Los conteos exactos no se copian aquí:
los imprime cada suite y los guardan `tools/express_golden/baseline_suites.json` y la
captura dorada; esta lista crece con cada plan de Express.

| Qué | Comando | Tiene que dar |
|---|---|---|
| Sintaxis | `php -l <archivo>` por cada `.php` que cambió (`git diff --name-only master -- '*.php'`) | `No syntax errors detected` en cada uno |
| Las tres suites fijadas | `php tools/test_write_ahead_puro.php`, `php tools/test_integracion_alcance.php`, `php tools/test_pos_scratch_puro.php` | salida 0 las tres |
| Los conteos | `php tools/express_baseline.php --comparar` | salida 0 y `Sin diferencias con la linea base.` Una prueba quitada en silencio no se nota en su suite, pero aquí sí |
| Entitlements, sin base | `php tools/test_express_entitlements.php` | `N de N pruebas OK` |
| Entitlements, con la base scratch | `php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_express_scratch.php` y lo mismo con `--enforce` | `N de N pruebas OK` en los dos modos |
| La empresa completa no cambió | `php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/express_golden.php --comparar` | salida 0 y `Sin diferencias`, con `PERMISSIONS_ENFORCE` apagado y encendido |
| Lo que tiene que seguir en verde | `php tools/test_inventario_stock.php`, `php tools/check_tenant_schema_orden.php`, `php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_pos_scratch.php`, `php -d extension=pdo_mysql tools/test_conduces_mysql.php`, `php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_write_ahead_api.php --tenant-db=wa_scratch --copia-db=wa_scratch_sin_gate`, `php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_write_ahead_ecf.php --tenant-db=wa_scratch`, `php -d extension=pdo_mysql tools/test_write_ahead_gate.php --tenant-db=wa_scratch --copia-db=wa_scratch_sin_gate` | salida 0 cada una, corridas DESPUÉS de las de Express (comparten la master de las pruebas del POS: un fallo que aparece solo después es un defecto de la ola, no de la suite) |
| Las líneas fijadas | `git diff master -- src/PermissionGate.php src/Middleware/AuthMiddleware.php src/Pos/PosAuth.php` | `PermissionGate` solo el cuerpo de `enforcing()`; `AuthMiddleware` solo el gancho de la edición; `PosAuth` solo la condición agregada. Ninguna línea fijada (`deny(... bool $siempre = false)`, el 403 de «principal no-usuario en ruta de app», el rechazo de credenciales de integración y «Credenciales requeridas») cambió |

La CI (`.github/workflows/express-api.yml`) repite la sintaxis 8.3, el baseline y las suites
puras en cada pull request y en cada push a `master` o a `feat/express-**`; las suites con
MySQL scratch y la captura dorada se corren a mano. En la fila de las líneas fijadas, `master`
es la rama que está en producción antes de la ola.

---

## 2. Migración master 015 (producción, la hace el dueño)

> La 014 del master es otra migración, `014_integracion_backup_estado_dgii.sql` (integración, ya en
> master): que su fila diga `APLICADA` o `FALTA` no dice nada de Express. La de Express es la fila **015**.

1. **Respaldo** de la base master, completa, desde phpMyAdmin.
2. Correr `tools/verificar_migraciones_master.sql` con la master seleccionada: la fila
   **013** tiene que decir `APLICADA` (si dice `FALTA`, esa primero).
3. Aplicar `db/master_migrations/015_tenants_edicion.sql` en phpMyAdmin **con la master
   seleccionada** (pestaña SQL, pegar todo, ejecutar). La última fila dice `columnas_ok = 4`.
   Si sale `#1049 Unknown database 'ALTO_elige_la_base_...'` (o `#1142`) no se cambió nada:
   phpMyAdmin estaba parado en otra base; ver el aviso del encabezado de la migración.
4. Correr el verificador otra vez: la fila **015** dice `APLICADA`.
5. Aplicar la 015 **una segunda vez**: es idempotente, no toca nada y el verificador sigue
   diciendo `APLICADA`.
6. Confirmar que **ninguna empresa dice `express`** con la Consulta 4 del verificador (viene
   comentada: se le quita el `-- ` a sus líneas y se ejecuta sola, con la 015 `APLICADA`).
   Lista toda empresa que no es `completa`, así que aquí tiene que salir **sin filas**.
   `php tools/verificar_express.php` no prueba eso: revisa lo que está mal puesto en esas
   empresas (tipos, módulos, certificado, rangos, grupo y credencial de integración) y dice
   `Sin problemas.` tanto cuando no hay ninguna Express como cuando hay una bien configurada.
   Sirve de segunda mirada: aquí tiene que imprimir `Sin problemas.` (salida 0); con salida 2,
   la master no tiene las columnas de la 015.
7. **Antes de subir el API**, confirmar que la master de producción trae `api_tokens.last_used`
   y `api_tokens.created_at`. El `SELECT` de `validateToken` (`src/Models/authModel.php`) las
   lee para **toda** empresa, `completa` incluida, y traga el error de una columna que falta:
   sin ellas cada sesión de cada empresa daría 401 en cuanto se suba el API. `updateLastUsed`
   también calla sus errores, así que hoy nada muestra que la master las tenga. Con la master
   seleccionada, en phpMyAdmin:

   ```sql
   SELECT COUNT(*) AS columnas_ok
     FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_tokens'
      AND COLUMN_NAME IN ('last_used', 'created_at');
   ```

   Tiene que dar `columnas_ok = 2`. Con menos, **no se sube el API**: se agrega la columna que
   falte (las dos las declara `db/master_schema.sql`, en `api_tokens`) y se repite la consulta.

## 3. Subir el API

Solo después del paso 2. El código tolera que las columnas de la 015 falten (sin ellas toda
empresa se lee como `completa`), pero ninguna empresa puede pasar a `express` hasta que existan.
Tras subir, una prueba de humo de la app completa en una empresa que ya exista.

**No se pone ninguna empresa en `express` en esta ola**: los planes 03 a 05 y 08 van primero.
Cuando llegue ese momento, el cambio se hace con `tools/tenant_edicion.php` (ensayo primero,
después `--apply`) y se revisa con `php tools/verificar_express.php`. Una empresa que está en un
grupo (`tenants.grupo_id` no NULL, cabeza o hermana) **no se convierte**: la credencial de
integración de cualquier empresa del grupo actúa por las demás sin pasar por los límites de
Express, y convertir deja en blanco la credencial de esa empresa. El preflight la rechaza (item
`grupo`), `tools/create_tenant.php` no acepta `--grupo` junto con `--edicion=express`, y el
verificador marca `EN_GRUPO` (y `INTEGRACION_ACTIVA` si la fila conserva su hash) en una fila
Express que se tocó a mano.

---

## 4. Reglas de reversa

Estas dos no se negocian:

1. **Nunca bajar el API por debajo de esta ola mientras exista una empresa Express.** El
   código anterior ignora `edicion` y le serviría todas las rutas a esa empresa.
2. **Nunca restaurar un respaldo de la master que no tenga las columnas de la 015.** El código
   lee una columna que falta como `completa`, así que las empresas Express perderían todas sus
   restricciones sin avisar. Antes de restaurar, comprobar que el respaldo trae las cuatro
   columnas; después de cualquier restauración, correr la Consulta 4 del verificador (dice qué
   empresas siguen siendo Express) y `php tools/verificar_express.php` (dice cuáles están mal
   puestas; sale con 2 si la master no tiene las columnas de la 015).

Y estas, del mismo apartado del anexo (14.5):

- Volver una empresa a `completa` **no es una parada segura**: levanta todas las restricciones.
  Las palancas de verdad son dejar `tipos_ecf_habilitados` vacío (la empresa ya no puede emitir
  nada), revocar los tokens de la empresa y, en el front, el rollback instantáneo de Vercel al
  build anterior de Express.
- `tenants.activo = 0` no apaga nada: la recepción de la DGII resuelve la empresa por RNC con
  `activo = 1` y contestaría 404.
- La edición se cambia con `tools/tenant_edicion.php`, nunca con un `UPDATE` a mano.
