<?php
require_once __DIR__ . '/src/Database.php';

try {
    $db = Database::getInstance()->getConnection();

    // Create ncf_sequences table
    $sql = "CREATE TABLE IF NOT EXISTS ncf_sequences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(10) NOT NULL UNIQUE,
        description VARCHAR(100) NOT NULL,
        current_value INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $db->exec($sql);
    echo "Table ncf_sequences created.\n";

    // Insert default B01 sequence if not exists
    $sql = "INSERT IGNORE INTO ncf_sequences (type, description, current_value) VALUES ('B01', 'Factura de Crédito Fiscal', 0)";
    $db->exec($sql);
    echo "Default B01 sequence inserted.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>