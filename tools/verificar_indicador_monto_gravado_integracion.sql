-- =============================================================================
-- verificar_indicador_monto_gravado_integracion.sql — SOLO LECTURA. No cambia nada.
--
-- Corre esto en la base MASTER ANTES de desplegar el modo «precios con ITBIS» de
-- /api/integracion/ecf (docs/specs/integracion-precios-con-itbis.md, §4.4).
--
-- Hasta ese deploy el API copiaba indicador_monto_gravado al XML sin cambiar el
-- calculo. Con el deploy, quien lo mande en 1 (en cualquier grafia: 1, "1", "01",
-- true) pasa a que sus precios se lean CON ITBIS: si sus precios van SIN ITBIS, el
-- total del comprobante baja cerca de un 15 % sin que nada falle. Esta consulta
-- dice si alguien lo manda hoy. Solo cuentan los envios normales: el set de pruebas
-- (strict_input) lo manda el runner de certificacion y el deploy no lo toca.
--
-- Uso: en phpMyAdmin entra a la base master (en el server: smhynzte_master_gratex)
-- y ejecuta todo el archivo; o, desde api-gratex:
--   php -d extension=pdo_mysql tools/produccion-lectura/sql.php --db=smhynzte_master_gratex --file=tools/verificar_indicador_monto_gravado_integracion.sql
--
-- 1) El veredicto: OK si nadie lo manda en 1 fuera del set de pruebas.
-- 2) El detalle de quien manda el campo y con que valor, para decidir si un
--    REVISAR es real (un tenant puede mandarlo en 1 a proposito, con precios con ITBIS).
--
-- La bitacora (integracion_request_log) existe desde el 2026-11-14 y guarda cada
-- cuerpo hasta 1 MiB (IntegracionRequestLog::TOPE_CUERPO). «cortados» cuenta los
-- cuerpos que se recortaron: uno recortado pudo perder el campo.
-- =============================================================================

-- 1) Veredicto
SELECT COUNT(*) AS envios_con_el_indicador_en_1,
       COUNT(DISTINCT tenant_id) AS tenants,
       MAX(created_at) AS ultimo_envio,
       IF(COUNT(*) = 0,
          'OK: nadie lo manda en 1 en un envio normal; se puede desplegar',
          'REVISAR: alguien lo manda en 1; si sus precios van sin ITBIS, su total bajaria cerca de un 15 % al desplegar') AS veredicto
  FROM integracion_request_log
 WHERE metodo = 'POST'
   AND ruta = '/api/integracion/ecf'
   AND request_body NOT LIKE '%strict_input%'
   AND request_body REGEXP '"indicador_monto_gravado"[[:space:]]*:[[:space:]]*(true|"?[[:space:]]*[+]?0*1(\\.0+)?[[:space:]]*"?)[[:space:]]*[,}]';

-- 2) Detalle: quien manda el campo en envios normales
SELECT tenant_id,
       COUNT(*) AS envios_con_el_campo,
       SUM(request_body REGEXP '"indicador_monto_gravado"[[:space:]]*:[[:space:]]*(true|"?[[:space:]]*[+]?0*1(\\.0+)?[[:space:]]*"?)[[:space:]]*[,}]') AS en_1,
       SUM(request_body LIKE '%[recortado:%') AS cortados,
       MIN(created_at) AS desde,
       MAX(created_at) AS hasta
  FROM integracion_request_log
 WHERE metodo = 'POST'
   AND ruta = '/api/integracion/ecf'
   AND request_body NOT LIKE '%strict_input%'
   AND request_body LIKE '%indicador_monto_gravado%'
 GROUP BY tenant_id
 ORDER BY en_1 DESC, envios_con_el_campo DESC;
