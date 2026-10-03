-- Corrige la secuencia E44 de Ferreventura (tenant 2, base smhynzte_002).
-- Diagnostico previo: tools/diag_e44_ferreventura.sql (2026-10-03).
--
-- Que paso: la certificacion dejo E440000000001..014 en facturas (ambiente
-- certecf). facturas.e_ncf es UNICO sin importar el ambiente y el emisor salta
-- los numeros ya ocupados, asi que el rango autorizado en produccion (1-5,
-- autorizacion 6005533112) quedaba inutilizable. Una fila sin autorizacion
-- (id 21, 0-100) termino dispensando el E440000000015, que la DGII rechazo.

START TRANSACTION;

-- 1) Fila 21 (ecf 0-100, sin autorizacion DGII): no es un rango real. En
--    produccion no consumio ningun numero valido (el 15 lo rechazo la DGII y se
--    devolvio), asi que se cierra vacia: deja de dispensar y deja de inflar el
--    "proximo e-NCF" de la pantalla.
UPDATE smhynzte_002.ncf_sequences
   SET numero_hasta = 0, current_value = 0
 WHERE id = 21 AND type = 'E44' AND ambiente = 'ecf' AND no_autorizacion IS NULL;

-- 2) Libera E440000000001..005 para produccion: los ocupan facturas de PRUEBA
--    de la certificacion. El numero sigue guardado en el xml_firmado de cada fila.
UPDATE smhynzte_002.facturas
   SET e_ncf = NULL
 WHERE tipo_ecf = '44' AND ambiente_dgii = 'certecf'
   AND e_ncf BETWEEN 'E440000000001' AND 'E440000000005';

COMMIT;

-- Verificacion: la fila 38 (1-5) debe quedar como la unica con capacidad y el
-- proximo E44 de produccion sera E440000000001.
SELECT id, ambiente, numero_desde, numero_hasta, current_value, no_autorizacion
FROM smhynzte_002.ncf_sequences WHERE type = 'E44' AND ambiente = 'ecf' ORDER BY numero_desde;

SELECT id, e_ncf, ambiente_dgii, estado_dgii
FROM smhynzte_002.facturas WHERE tipo_ecf = '44' ORDER BY id;
