<?php
/**
 * Hora de la app: la de Republica Dominicana, UTC-4 siempre (RD no tiene horario
 * de verano).
 *
 * El hosting esta en la hora central de EE. UU., que si cambia con el horario de
 * verano. Sin fijar zona, PHP y MySQL tomaban esa hora y todo lo que la app sella
 * (FechaHoraFirma y FechaEmision del e-CF, facturas.date, audit_logs.created_at)
 * quedaba 1 hora detras de RD de marzo a noviembre y 2 de noviembre a marzo.
 *
 * PHP y MySQL cambian JUNTOS: auth_tokens_emitidos.expira_at se escribe con la hora
 * de PHP y se compara con NOW() de MySQL (authSeedModel). Cargar este archivo ya deja
 * PHP en hora de RD; lo cargan index.php, Database.php y MasterDatabase.php (y con
 * ellas las paginas de public/ y las herramientas), y las dos clases llaman
 * aplicarEn() al conectar. Prueba: tools/test_zona_horaria.php.
 */
final class ZonaHoraria
{
    public const PHP = 'America/Santo_Domingo';

    // Desfase y no nombre de zona: un nombre necesita las tablas de zonas de MySQL,
    // que el hosting compartido puede no tener, y RD no cambia de hora.
    public const MYSQL = '-04:00';

    /** Pone la sesion de MySQL en hora de RD: NOW(), CURDATE() y DEFAULT CURRENT_TIMESTAMP. */
    public static function aplicarEn(PDO $conexion): void
    {
        $conexion->exec("SET time_zone = '" . self::MYSQL . "'");
    }
}

date_default_timezone_set(ZonaHoraria::PHP);
