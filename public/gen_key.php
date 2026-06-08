<?php
/**
 * gen_key.php — Genera una MASTER_ENCRYPTION_KEY (64 hex = 32 bytes AES-256).
 *
 * Servido directo (esta bajo /api/public/, que el .htaccess permite).
 * Para usuarios sin CLI: abre en el navegador, copia la clave al .env como
 * MASTER_ENCRYPTION_KEY y BORRA este archivo.
 *
 *   https://gratex.net/api/public/gen_key.php
 *
 * Genera una clave nueva en cada visita; usa SOLO la primera y no la cambies
 * despues (cifra/descifra las credenciales de los tenants).
 */

header('Content-Type: text/plain; charset=utf-8');

echo "MASTER_ENCRYPTION_KEY=" . bin2hex(random_bytes(32)) . "\n\n";
echo "Copia la linea de arriba al .env. Luego BORRA este archivo.\n";
