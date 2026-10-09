<?php
// Script to convert MySQL schema to SQLite and create the local DB
$mysqlSchema = file_get_contents(__DIR__ . '/actual_schema.sql');

// Basic replacements for SQLite compatibility
$sqliteSchema = $mysqlSchema;
$sqliteSchema = preg_replace('/ENGINE=InnoDB[^;]+;/', ';', $sqliteSchema);
$sqliteSchema = preg_replace('/int\([0-9]+\)/', 'INTEGER', $sqliteSchema);
$sqliteSchema = preg_replace('/int NOT NULL AUTO_INCREMENT/', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sqliteSchema);
$sqliteSchema = preg_replace('/int unsigned NOT NULL AUTO_INCREMENT/', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sqliteSchema);
$sqliteSchema = preg_replace('/int\s+AUTO_INCREMENT/', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sqliteSchema);
$sqliteSchema = preg_replace('/AUTO_INCREMENT=[0-9]+/', '', $sqliteSchema);
$sqliteSchema = preg_replace('/COLLATE\s+[a-zA-Z0-9_]+/', '', $sqliteSchema);
$sqliteSchema = preg_replace('/CHARACTER SET\s+[a-zA-Z0-9_]+/', '', $sqliteSchema);
$sqliteSchema = preg_replace('/enum\([^)]+\)/', 'TEXT', $sqliteSchema);
$sqliteSchema = preg_replace('/json/', 'TEXT', $sqliteSchema);
$sqliteSchema = preg_replace('/datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP/', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $sqliteSchema);
$sqliteSchema = preg_replace('/timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP/', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $sqliteSchema);
$sqliteSchema = preg_replace('/timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP/', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $sqliteSchema);
$sqliteSchema = preg_replace('/timestamp NULL DEFAULT CURRENT_TIMESTAMP/', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $sqliteSchema);
$sqliteSchema = preg_replace('/timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP/', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $sqliteSchema);
$sqliteSchema = preg_replace('/timestamp NULL DEFAULT NULL/', 'DATETIME', $sqliteSchema);
$sqliteSchema = preg_replace('/timestamp/', 'DATETIME', $sqliteSchema);
$sqliteSchema = preg_replace('/datetime/', 'DATETIME', $sqliteSchema);

// Remove MySQL specific keys and constraints that cause SQLite issues in CREATE TABLE
$lines = explode("\n", $sqliteSchema);
$finalLines = [];
foreach ($lines as $line) {
    // Skip KEY, UNIQUE KEY, FULLTEXT KEY, CONSTRAINT
    if (preg_match('/^\s*(KEY|UNIQUE KEY|FULLTEXT KEY|CONSTRAINT|PRIMARY KEY \(`)/', $line)) {
        continue;
    }
    $finalLines[] = $line;
}
$sqliteSchema = implode("\n", $finalLines);

// Clean up trailing commas before closing parenthesis
$sqliteSchema = preg_replace('/,\s*\n\)/', "\n)", $sqliteSchema);

$dbPath = __DIR__ . '/../app/databases/local_pos.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $statements = explode(';', $sqliteSchema);
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt) {
            $pdo->exec($stmt);
        }
    }
    
    // Add Sync columns to important tables
    $tablesToSync = ['sales', 'sale_items', 'products', 'customers', 'orders', 'order_items'];
    foreach ($tablesToSync as $table) {
        try {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN sync_status INTEGER DEFAULT 0");
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN global_id TEXT");
        } catch (Exception $e) {
            // Table might not exist or column exists
        }
    }
    
    echo "SQLite database created successfully at: $dbPath\n";
} catch (Exception $e) {
    echo "Error creating SQLite DB: " . $e->getMessage() . "\n";
}
