<?php
// TEMPORARY MAINTENANCE FILE — DELETE AFTER USE (hay un boton al final que lo borra).
//
// Cifra una credencial con la MASTER_ENCRYPTION_KEY del .env del SERVER y
// devuelve el blob en hex + el UPDATE listo para pegar en phpMyAdmin.
// Pensado para hosting compartido sin CLI, donde no se puede correr
// tools/encrypt_credential.php.
//
// Token-gated con ENCRYPT_TOKEN (o READLOG_TOKEN como respaldo), igual que
// readlog.php / cert_run.php. El token y la credencial viajan por POST para que
// no queden en la URL ni en los access logs del server.

require_once __DIR__ . '/../src/MasterDatabase.php';
require_once __DIR__ . '/../src/TenantResolver.php';
require_once __DIR__ . '/../src/Database.php';

Database::loadEnv();

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Referrer-Policy: no-referrer');

$expected = (string) (getenv('ENCRYPT_TOKEN') ?: ($_ENV['ENCRYPT_TOKEN']
    ?? (getenv('READLOG_TOKEN') ?: ($_ENV['READLOG_TOKEN'] ?? ''))));

$isPost   = $_SERVER['REQUEST_METHOD'] === 'POST';
$token    = (string) ($_POST['token'] ?? '');
$authed   = $expected !== '' && $isPost && hash_equals($expected, $token);

$error = '';
$notice = '';
$hex = '';
$sql = '';
$tenants = [];

if ($expected === '') {
    $error = 'No hay ENCRYPT_TOKEN ni READLOG_TOKEN en el .env del server. Agrega uno y recarga.';
} elseif ($isPost && !$authed) {
    $error = 'Token invalido.';
}

// --------------------------------------------------------------- self-delete
if ($authed && ($_POST['action'] ?? '') === 'delete') {
    $self = __FILE__;
    if (@unlink($self)) {
        echo '<p style="font:14px system-ui">Archivo borrado del server. Ya no es accesible.</p>';
        exit;
    }
    $error = 'No se pudo borrar el archivo. Borralo manualmente por el File Manager de cPanel: ' . $self;
}

// ------------------------------------------------------------------- trabajo
if ($authed && ($_POST['action'] ?? '') === 'encrypt') {
    $plain = (string) ($_POST['plain'] ?? '');
    if ($plain === '') {
        $error = 'Escribe la credencial a cifrar.';
    } else {
        try {
            $blob = TenantResolver::encrypt($plain);
            // Round-trip obligatorio: no mostrar un blob que no descifre.
            if (TenantResolver::decrypt($blob) !== $plain) {
                throw new RuntimeException('El blob no descifra al valor original.');
            }
            $hex = strtoupper(bin2hex($blob));

            $sets = ["db_pass_encrypted = UNHEX('{$hex}')"];
            foreach (['db_host', 'db_name', 'db_user', 'db_port'] as $col) {
                $v = trim((string) ($_POST[$col] ?? ''));
                if ($v !== '') {
                    $sets[] = "{$col} = '" . str_replace("'", "''", $v) . "'";
                }
            }
            $tenantId = (int) ($_POST['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                $sql = "UPDATE tenants SET\n  " . implode(",\n  ", $sets) . "\nWHERE id = {$tenantId};";
            } else {
                $sql = "UPDATE tenants SET\n  " . implode(",\n  ", $sets) . "\nWHERE id = <PON_EL_ID>;";
            }
            $notice = 'Blob verificado: descifra correctamente al valor original.';
        } catch (Throwable $e) {
            $error = 'Error al cifrar: ' . $e->getMessage();
        }
    }
}

// Listado de tenants (solo lectura) para confirmar que fila tocar.
if ($authed) {
    try {
        $master = MasterDatabase::getInstance()->getConnection();
        $tenants = $master->query(
            "SELECT id, nombre, tipo, activo, db_host, db_port, db_name, db_user,
                    LENGTH(db_pass_encrypted) AS pass_bytes
               FROM tenants ORDER BY id"
        )->fetchAll();
    } catch (Throwable $e) {
        $notice .= ' (No se pudo leer la tabla tenants: ' . $e->getMessage() . ')';
    }
}

function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cifrar credencial</title>
<style>
  body { font: 14px/1.5 system-ui, sans-serif; max-width: 860px; margin: 24px auto; padding: 0 16px; color: #111; }
  h1 { font-size: 18px; }
  .warn { background: #fff4e5; border: 1px solid #f0b429; padding: 10px 14px; border-radius: 6px; }
  .err  { background: #fdecea; border: 1px solid #e57373; padding: 10px 14px; border-radius: 6px; }
  .ok   { background: #e8f5e9; border: 1px solid #66bb6a; padding: 10px 14px; border-radius: 6px; }
  label { display: block; margin: 10px 0 3px; font-weight: 600; font-size: 12px; }
  input { width: 100%; padding: 7px 9px; border: 1px solid #bbb; border-radius: 5px; font: inherit; }
  pre { background: #f5f5f5; border: 1px solid #ddd; padding: 12px; border-radius: 6px;
        white-space: pre-wrap; word-break: break-all; font-size: 12px; }
  table { border-collapse: collapse; width: 100%; font-size: 12px; margin-top: 8px; }
  th, td { border: 1px solid #ddd; padding: 5px 7px; text-align: left; }
  th { background: #f5f5f5; }
  button { margin-top: 14px; padding: 8px 16px; font: inherit; border-radius: 5px;
           border: 1px solid #888; background: #fff; cursor: pointer; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
</style>

<h1>Cifrar credencial (AES-256-GCM)</h1>
<p class="warn"><strong>Archivo temporal.</strong> Borralo del server apenas termines — hay un boton al final.</p>

<?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
<?php if ($notice): ?><p class="ok"><?= h($notice) ?></p><?php endif; ?>

<?php if ($hex): ?>
  <h2 style="font-size:15px">Blob en HEX</h2>
  <pre><?= h($hex) ?></pre>
  <h2 style="font-size:15px">SQL para phpMyAdmin (base maestra)</h2>
  <pre><?= h($sql) ?></pre>
  <p>Despues verifica con:<br>
  <code>SELECT id, nombre, db_host, db_name, db_user, LENGTH(db_pass_encrypted) FROM tenants;</code></p>
<?php endif; ?>

<?php if ($tenants): ?>
  <h2 style="font-size:15px">Tenants actuales</h2>
  <table>
    <tr><th>id</th><th>nombre</th><th>tipo</th><th>activo</th><th>db_user</th><th>db_host</th><th>db_name</th><th>pass bytes</th></tr>
    <?php foreach ($tenants as $t): ?>
      <tr>
        <td><?= h($t['id']) ?></td><td><?= h($t['nombre']) ?></td><td><?= h($t['tipo']) ?></td>
        <td><?= (int) $t['activo'] === 1 ? 'si' : 'NO' ?></td>
        <td><?= h($t['db_user']) ?></td><td><?= h($t['db_host']) ?></td>
        <td><?= h($t['db_name']) ?></td><td><?= h($t['pass_bytes']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<form method="post" autocomplete="off">
  <input type="hidden" name="action" value="encrypt">
  <label>Token</label>
  <input type="password" name="token" required>
  <label>Credencial a cifrar (la password de la DB)</label>
  <input type="password" name="plain" required>

  <p style="margin-top:18px;font-size:12px;color:#555">
    Opcional — si los llenas, el SQL incluye tambien estos campos:
  </p>
  <div class="grid">
    <div><label>tenant_id</label><input name="tenant_id" placeholder="1"></div>
    <div><label>db_host</label><input name="db_host" placeholder="localhost"></div>
    <div><label>db_name</label><input name="db_name" placeholder="smhynzte_new_gratexdb"></div>
    <div><label>db_user</label><input name="db_user" placeholder="smhynzte_edwin"></div>
  </div>
  <button type="submit">Cifrar</button>
</form>

<hr style="margin:28px 0">
<form method="post" onsubmit="return confirm('Borrar este archivo del server?')">
  <input type="hidden" name="action" value="delete">
  <label>Token</label>
  <input type="password" name="token" required>
  <button type="submit" style="border-color:#c62828;color:#c62828">Borrar este archivo del server</button>
</form>
