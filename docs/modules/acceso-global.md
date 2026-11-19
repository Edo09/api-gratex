# Acceso global (usuario de soporte)

Un usuario que puede entrar a **todas las empresas** tipo app con su propio login, para darles
soporte sin tener una cuenta en cada una. En la web (app.fiscalpoint.com.do) ve un selector de
empresa en la barra superior.

> Antes, cada usuario pertenecía a una sola empresa (`master.users.tenant_id`) y su sesión quedaba
> atada a ella (`master.api_tokens.tenant_id`). Sigue siendo así para todos: el acceso global es
> una columna aparte que solo se enciende a mano.

## Cómo funciona

- **`master.users.acceso_global`** (`TINYINT(1)`, default `0`, [master 019](../../db/master_migrations/019_users_acceso_global.sql),
  aplicada en producción el 2026-11-15). Con `1`, el usuario puede abrir sesión en otra empresa.
- La empresa de cada petición sale del **token**, no del usuario: `AuthMiddleware` resuelve la
  empresa del token y `PermissionGate` busca el rol del usuario **en esa empresa**. Por eso un
  usuario de soporte con rol `admin` es el administrador de la empresa en la que está.
- **Empresas disponibles:** tipo `app`, activas y que no sean solo Express (`edicion` distinta de
  `express`). Una empresa `ambas` sí: la web corre para ella como una completa. Las de integración
  no tienen base ni web.
- **El token se valida en cada petición** (`authModel::validateToken`). Un token en la empresa del
  usuario vale como siempre, sin consultas extra. Uno en **otra** empresa vale solo si el usuario
  tiene acceso global, la empresa sigue disponible y la sesión no lleva más de **12 horas sin uso**
  (`AccesoGlobal::HORAS_INACTIVIDAD`, desde su último uso o desde que se abrió).
- Si se niega por algo definitivo (le quitaron el acceso, la empresa dejó de estar disponible o la
  sesión venció), el token además **se revoca**: devolverle el acceso o reactivar la empresa no lo
  revive, hay que entrar de nuevo. Un fallo al leer la columna o la empresa solo niega esa petición.
- Las reglas puras viven en [`src/AccesoGlobal.php`](../../src/AccesoGlobal.php).

## Endpoints

Usan el sobre `{success, data | error, codigo}` del resto de `/api/auth/*`. La ruta `auth` es
`public` en `config/permissions.php`: cada endpoint valida la sesión por su cuenta.

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/auth/empresas` | `{actual, empresas: [{id, nombre, rnc, ambiente, propia}]}`, ordenadas por nombre. `actual` = empresa de la sesión; `propia` = es la empresa del usuario. 403 `SIN_ACCESO_GLOBAL` para cualquier otro usuario. |
| `POST` | `/api/auth/empresa` | Cuerpo `{tenant_id}`. Abre una sesión **nueva** en esa empresa: responde `{token, user}`, igual que el login. 400 `EMPRESA_REQUERIDA`, 403 `SIN_ACCESO_GLOBAL`, 404 `EMPRESA_NO_DISPONIBLE`, 409 `MISMA_EMPRESA` (la sesión ya está en esa empresa: no abre otra). |
| `POST` | `/api/auth/signout` | Con `{todas: true}` (lo manda «Cerrar sesión» de la web) y un usuario de soporte, cierra **todas** sus sesiones, en todas las empresas, pestañas y equipos. Para cualquier otro usuario, y sin el campo (el signout del POS), cierra solo la sesión actual, como siempre. |

El login y `GET /api/auth/me` de un usuario con acceso global traen además
`acceso_global: true` y `empresa: {id, nombre, rnc, ambiente, propia}` (la empresa de la sesión;
`propia: false` = está en la de un cliente). Para cualquier otro usuario la respuesta no cambia: esas
claves no aparecen.

**Token nuevo, no el mismo movido.** El token se comparte entre las pestañas del navegador. Si el
cambio moviera el token, otra pestaña que sigue mostrando la empresa anterior (una factura a medio
hacer, con sus clientes y productos) mandaría sus peticiones a la nueva y podría emitir un e-CF en
la empresa equivocada. Con uno nuevo, cada pestaña sigue en la empresa de su token. La sesión de la
que sale no se cierra; la cierran el vencimiento de 12 horas y «Cerrar sesión».

`/me` y el canje del botón POS (`POST /api/auth/pos-handoff/canje`) usan la empresa de la sesión:
un usuario de soporte que abre el POS desde la empresa de un cliente lo abre en esa empresa. Si al
canjear ya no puede estar en ella, el canje responde 403 `SIN_ACCESO_GLOBAL`.

## La cuenta de soporte no se toca desde la app

Quien pudiera cambiarle la contraseña, el correo o el rol a la cuenta de soporte desde la pantalla
Usuarios de su empresa se quedaría con el acceso a todas. Por eso, para un usuario con acceso
global:

- `PUT /api/users/{id}` lo puede hacer **solo el mismo usuario** (su nombre, correo o contraseña).
  Cualquier otro, aunque sea admin, recibe 403.
- Su **rol** no cambia desde la app, ni por `PUT /api/users/{id}` ni por `PUT /api/roles/assign`
  (403): usa el mismo nombre de rol en todas las empresas, así que cambiarlo en una lo cambiaría en
  todas.
- `DELETE /api/users/{id}` responde 403.

Todo eso se hace por SQL en la master.

## Bitácora

Cada cambio de empresa deja una fila con `tenant_id` = la empresa a la que entra: acción
`CAMBIO_EMPRESA` (en la web, "Entrada de soporte"), con el usuario y la sesión nueva. La empresa de
origen solo se nombra si es la del propio usuario de soporte (la del operador); si venía de otro
cliente, la fila no dice de cuál. Lo que haga después queda a su nombre, igual que el de cualquier usuario.

**Pero el administrador de una empresa cliente no ve nada de eso** (decisión del dueño, 2026-11-19: «mi
usuario no debería aparecer en la bitácora de los tenants»). `GET /api/audit-logs` y sus subrutas
`/resumen` y `/facetas` (`AuditLogModel::search/count/summary/facets`) dejan fuera toda fila cuyo
`user_id` sea de un usuario con `acceso_global = 1` **en una empresa que no es la suya**
(`users.tenant_id <> audit_logs.tenant_id`): la entrada, lo que hizo y su `LOGOUT`. Los totales, el
resumen, los filtros de usuario y acción y el contador de la paginación salen ya sin esas filas.

- **No se borra ni se deja de escribir nada.** El filtro es de lectura: las filas siguen en la master y
  la [vista de operaciones](auditoria.md#vista-web-de-operaciones) (`public/audit_logs.html`, todas las
  empresas) las muestra. El SQL de más abajo («Qué hizo en empresas cliente») sigue valiendo.
- **En su propia empresa se ve como un empleado más** (su login, su logout, la vuelta a su empresa):
  el filtro solo aplica en empresas ajenas.
- **Lee la marca de hoy.** Si a un usuario le quitan `acceso_global`, sus filas viejas en las empresas
  cliente vuelven a aparecer; al devolvérselo, se ocultan otra vez.
- Sin multi-tenant, o en una master sin la 019 (la consulta de `users.acceso_global` falla), el filtro
  se apaga solo y la bitácora sale entera: nunca se cae por esto.
- La acción `CAMBIO_EMPRESA` ya no aparece en el filtro «Acción» de ninguna empresa cliente.

## Dar y quitar el acceso

La master 019 ya está aplicada. Por SQL en la master:

```sql
-- Dar el acceso
UPDATE users SET acceso_global = 1 WHERE username = '<usuario>';

-- Quitarlo: sus sesiones en otras empresas dejan de valer y se revocan en su siguiente petición
UPDATE users SET acceso_global = 0 WHERE username = '<usuario>';

-- Cerrarle ya todas las sesiones (si la cuenta pudo quedar comprometida)
UPDATE api_tokens t JOIN users u ON u.id = t.user_id SET t.is_active = 0
 WHERE u.username = '<usuario>' AND t.is_active = 1;

-- Revocar los equipos de caja (POS) que habilitó en empresas cliente: sobreviven a quitarle el acceso
UPDATE pos_equipos e JOIN users u ON u.id = e.habilitado_por SET e.revocado_at = NOW()
 WHERE u.username = '<usuario>' AND e.tenant_id <> u.tenant_id AND e.revocado_at IS NULL;

-- Qué hizo en empresas cliente (empleados del POS, usuarios, roles...)
SELECT a.created_at, a.tenant_id, a.module, a.action, a.description
  FROM audit_logs a JOIN users u ON u.id = a.user_id
 WHERE u.username = '<usuario>' AND a.tenant_id <> u.tenant_id
 ORDER BY a.created_at DESC;

-- Quiénes lo tienen
SELECT id, tenant_id, username, role FROM users WHERE acceso_global = 1;
```

El usuario debería tener rol `admin` (cada empresa tiene un rol de sistema `admin`), contraseña
fuerte y pertenecer a la empresa del operador (Gratex), en edición completa. Con ese acceso entra a
los datos fiscales de todos los clientes.

## Orden de despliegue

1. **Master 019** en la base master (hecho el 2026-11-15). Solo toca la master: no hay nada que
   correr en las bases de las empresas.
2. **API.** Subirla antes de la migración no rompía el login de nadie: la columna se lee aparte y,
   si no existe, nadie tiene acceso global (lo prueba `tools/test_acceso_global.php`).
3. **Web** (fiscalo-react): el selector aparece solo si el login trae `acceso_global: true`, así que
   también se puede publicar antes.

## Límites

- Una empresa solo Express no aparece en la lista: no usa la web, y la guarda de rutas de Express no
  dejaría salir de ella con `POST /api/auth/empresa`.
- Si un usuario con acceso global pertenece a una empresa solo Express, esa guarda le niega
  `/api/auth/empresas`. Debe pertenecer a una empresa completa.
- En el reporte de ventas por usuario de una empresa cliente, lo que venda el usuario de soporte
  sale como "Usuario #id": ese reporte solo nombra a los usuarios de la empresa.
- Los equipos de caja que el usuario de soporte habilite en una empresa cliente no dependen de su
  acceso: al quitárselo, revocarlos con el SQL de arriba.
- El acceso global también es lo que abre **Cobros de tenants** (`/api/cobros-tenants`: lo que la plataforma le cobra a sus
  empresas, ver [cobros.md](cobros.md)). Se exige en el servidor y no depende de la empresa en la que esté la sesión. Quitar el
  acceso lo cierra de inmediato; lo ya cobrado queda en la master.

## Pruebas

- `tools/test_acceso_global.php` (en la línea base de suites, `tools/express_baseline.php`):
  - Parte 1, sin base: las reglas de `AccesoGlobal` (empresa disponible, sesión permitida,
    vencimiento, lo que se muestra de una empresa, el cuerpo del cambio).
  - Parte 2, el `authModel`, `userModel` y `RoleModel` reales contra un master SQLite: validación
    del token, revocación y vencimiento, login, `/me`, canje del POS, lista de empresas, cambio de
    empresa (con el 409), protección de la cuenta, cierre de todas las sesiones, y un master sin la
    columna.
  - Parte 3, leyendo el fuente: que los controllers pasen la empresa de la sesión, el actor y los
    códigos (los modelos solos no lo garantizan).
- En la web, `scripts/test-acceso-global.ts` (fiscalo-react) prueba la búsqueda y los rótulos del
  selector.
