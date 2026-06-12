<?php
require_once __DIR__ . '/src/Database.php';
$c = Database::getInstance()->getConnection();
$q = $c->query('SHOW CREATE TABLE facturas');
print_r($q->fetch(PDO::FETCH_ASSOC));
