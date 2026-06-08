<?php
/**
 * Entrypoint web para la migracion de Gratex (tenant #1).
 *
 * Servido directo porque esta bajo /api/public/ (el .htaccess permite archivos
 * existentes ahi; el resto de /api/* lo captura index.php/Router).
 *
 * La logica real + el token de seguridad estan en tools/migrate_gratex.php.
 *   https://gratex.net/api/public/migrate_gratex.php?token=TU_TOKEN
 *
 * BORRA este archivo y tools/migrate_gratex.php del server tras migrar.
 */

require __DIR__ . '/../tools/migrate_gratex.php';
