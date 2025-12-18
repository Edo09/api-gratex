<?php
$password = "password123";
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
echo "Password: " . $password . "\n";
echo "Hash: " . $hash . "\n";
?>
