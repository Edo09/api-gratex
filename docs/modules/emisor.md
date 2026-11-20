# Módulo: Datos de la empresa (emisor_config)

Los datos fiscales y de contacto de la empresa que emite: razón social, RNC, nombre
comercial, dirección, teléfono, etc. Salen en la Representación Impresa de cada factura y
cotización y viajan dentro del nodo `Emisor` del XML firmado de **cada** e-CF.

Viven en la fila única (`id = 1`) de la tabla `emisor_config` de la base de la empresa
(`db/tenant_schema.sql`). Una empresa de integración (`tipo = integracion`) no tiene base
propia ni esa tabla: su `GET` sale del registro de la empresa en la master y no se edita.

## Endpoints (`/api/emisor`)

| Método | Quién | Qué hace |
|---|---|---|
| `GET` | **Cualquier usuario** de la app (`'authenticated'` en `config/permissions.php`) | La fila de `emisor_config` sin el `id`, más `ambiente` (el de la empresa) y `fuente: "emisor_config"`. Una empresa de la edición Express recibe además `certificado`. |
| `PUT` | El administrador (módulo `emisor`) | Cambia los datos de la empresa que se pueden cambiar. Responde lo mismo que el `GET`, ya con los datos nuevos. |

Cualquier otro método responde 405. La pantalla es **Configuración › Datos de empresa**.

### `PUT /api/emisor`

Cuerpo JSON **parcial**: solo se mira lo que viene y el resto se conserva.

| Dato | Se puede cambiar | Regla (la del XSD de la DGII, `samples/e-CF 31 v.1.0.xsd`) |
|---|---|---|
| `rnc` | **No** | — |
| `razon_social` | **No** | — |
| `nombre_comercial` | Sí | Opcional, hasta 150 caracteres |
| `sucursal` | Sí | Opcional, hasta 20 caracteres |
| `direccion` | Sí | **Obligatoria**, hasta 100 caracteres |
| `provincia` | Sí | Opcional. Código de 6 dígitos del catálogo DGII, de tipo `PROVINCIA` |
| `municipio` | Sí | Opcional. Código de 6 dígitos del catálogo, de tipo `MUNICIPIO` o `DISTRITO`, y **de la provincia elegida** (sus 2 primeros dígitos coinciden) |
| `telefono` | Sí | Opcional. 10 dígitos; se guarda como `809-555-1234` (acepta `8095551234`, `(809) 555-1234`, `+1 809 555 1234`…) |
| `correo` | Sí | Opcional, hasta 80 caracteres, con el patrón del XSD (`CorreoValidationType`) |
| `website` | Sí | Opcional, hasta 50 caracteres y sin espacios |
| `actividad_economica` | Sí | Opcional, hasta 100 caracteres |

- Los textos se recortan y los espacios y saltos de línea se juntan en un solo espacio.
  Los límites cuentan **caracteres**, no bytes (`ñ` y `é` cuentan 1).
- Un dato opcional en blanco (`""` o `null`) deja la columna en `NULL`; el XML solo omite ese
  elemento.
- Un dato **igual al guardado** no es un cambio y no se valida de nuevo: un valor heredado que
  no cumple las reglas de hoy no impide guardar el resto. Tampoco lo es escribir el teléfono sin
  guiones si ya estaba con ellos.
- Las claves que no son datos editables (`ambiente`, `fuente`, `certificado`,
  `fecha_vencimiento_secuencia`, `id`…) se ignoran, de modo que un cliente puede devolver tal
  cual lo que leyó con el `GET`.
- **RNC y razón social**: si el cuerpo los trae **distintos** de los guardados (el RNC se compara
  por sus dígitos; la razón social sin distinguir mayúsculas ni espacios de más), la respuesta es
  422 y **no se guarda nada**, tampoco lo demás del cuerpo. Si los trae iguales se ignoran. Solo
  soporte los cambia, por SQL.

| Código | Cuándo |
|---|---|
| 200 | Guardado. `data` = la fila ya guardada (igual que el `GET`). |
| 400 | El cuerpo no es un objeto JSON. |
| 401 | Sin sesión. |
| 403 | La sesión no es de un usuario, o su rol no es `admin` ni tiene el módulo `emisor`. |
| 409 | Empresa de integración (sin base propia). |
| 422 | Algún dato no cumple las reglas, el RNC o la razón social son distintos, o no hay cambios. `error` trae el primer mensaje y `campos` el detalle `{ campo: mensaje }`. |
| 500 | No se pudo guardar. El texto técnico va al log, no a la pantalla. |

### Quién puede

`config/permissions.php` ya pedía el módulo `emisor` para todo lo que no sea `GET`
(`'emisor' => ['GET' => 'authenticated', '*' => 'emisor']`) y `emisor` está entre los módulos
**solo-admin**: el rol de sistema `admin` (`*`) puede; el rol `user` de sistema, no. Un rol propio
puede recibir el módulo `emisor` desde `/api/roles` y entonces también edita.

Además del gate, el controller lo exige **siempre**, aunque `PERMISSIONS_ENFORCE` esté en modo
sombra (el valor por defecto): el gate en sombra solo anota lo que negaría, y estos datos viajan en
cada e-CF. Pasa el rol `admin` (por nombre) o cualquiera con el módulo `emisor`. Un rol que no
puede recibe 403 y queda un `ACCESS_DENIED` del módulo `emisor` en la bitácora.

La edición **Express** no incluye `PUT /api/emisor` (`config/editions.php` solo permite el `GET`):
una empresa Express recibe 403 `EDICION_NO_INCLUYE`.

## Por qué se valida tanto

Lo que se guarda aquí va en el XML firmado de **todos** los e-CF de la empresa. Un valor que el
XSD no acepta (un teléfono sin el formato, una dirección de 101 caracteres, un código de
provincia que no existe) no se nota al guardarlo: se nota cuando la DGII rechaza la siguiente
factura. Por eso `src/Utils/EmisorEdicion.php` aplica las reglas del XSD en el servidor aunque la
pantalla ya las haya revisado, y `tools/test_emisor_edicion.php` arma un E31 real con el builder y
lo valida contra el XSD oficial (y comprueba que el XSD rechaza justo lo que aquí no se deja guardar).

`EmisorConfigModel::update()` hace el `UPDATE` y vuelve a leer la fila dentro de una transacción: si
no quedó como se pidió (por ejemplo una columna más corta de lo que dice el esquema, con MySQL sin
modo estricto, recortaría el texto en silencio) se deshace y responde 500 en vez de decir que guardó.

## Bitácora

Cada cambio deja un `UPDATE` del módulo `emisor` (entidad `emisor_config`, `entity_id` 1) con
`old_values` y `new_values` **solo de lo que cambió** y una descripción con palabras de la pantalla
("Datos de la empresa actualizados: la dirección, el teléfono."). Un intento sin permiso deja un
`ACCESS_DENIED`. Ver [auditoria.md](auditoria.md).

## Qué pasa con lo ya emitido

Las facturas y cotizaciones **ya emitidas** se vuelven a dibujar (PDF) con los datos de
`emisor_config` de hoy, no con los de la fecha en que se emitieron: cambiar la dirección o el
teléfono cambia cómo se ven al reimprimirlas. El XML firmado de un e-CF ya enviado no cambia. El RNC
y la razón social, que son la identidad del comprobante, no se pueden editar.

## Pruebas

- `php tools/test_emisor_edicion.php` — reglas, límites, modelo sobre SQLite y contrato con el XSD
  (sin base ni servidor; corre en el CI).
- `php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_emisor_edicion_api.php --tenant-db=wa_scratch`
  — de punta a punta con la API real sobre la base de prueba: admin, `user`, un rol propio con y sin
  el módulo, en modo sombra y con el gate aplicado; deja la base como la encontró. Ver
  [write_ahead_test_lib.php](../../tools/write_ahead_test_lib.php) para preparar la base.
