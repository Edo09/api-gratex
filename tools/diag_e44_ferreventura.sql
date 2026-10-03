-- Diagnostico (SOLO LECTURA) del contador E44 de Ferreventura.
-- Resuelve la base del tenant desde master y consulta rangos, facturas E44 y
-- auditoria (registro de rangos e intentos de emision E44).

SELECT id, nombre, ambiente, db_name
FROM smhynzte_master_gratex.tenants WHERE rnc = '132615123';

SET @db = (SELECT db_name FROM smhynzte_master_gratex.tenants WHERE rnc = '132615123' LIMIT 1);

SET @q = CONCAT('SELECT id, ambiente, numero_desde, numero_hasta, current_value, no_autorizacion, description, created_at, updated_at FROM `', @db, '`.ncf_sequences WHERE type = ''E44'' ORDER BY ambiente, numero_desde');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = CONCAT('SELECT id, e_ncf, date, client_name, total, estado_dgii, ambiente_dgii, secuencia_utilizada FROM `', @db, '`.facturas WHERE e_ncf LIKE ''E44%'' ORDER BY e_ncf');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = CONCAT('SELECT created_at, username, new_values FROM `', @db, '`.audit_logs WHERE action = ''NCF_RANGE_REGISTER'' ORDER BY created_at');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = CONCAT('SELECT created_at, username, success, entity_id, description, LEFT(error_message, 160) AS error FROM `', @db, '`.audit_logs WHERE action = ''EMIT'' AND new_values REGEXP ''"tipo_ecf": ?"?44'' ORDER BY created_at');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
