<?php
require_once __DIR__ . '/../app/app.php';

try {
    $pdo = Database::pdo();
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $schema = [];
    foreach ($tables as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $schema[] = $create['Create Table'] ?? '';
    }
    
    file_put_contents(__DIR__ . '/actual_schema.sql', implode(";\n\n", $schema) . ";\n");
    echo "Schema dumped successfully to actual_schema.sql\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
