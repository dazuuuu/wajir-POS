<?php
$sqlite = new PDO('sqlite:' . __DIR__ . '/../app/databases/local_pos.sqlite');
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$mysql = new PDO('mysql:host=localhost;dbname=wajir_pos;charset=utf8mb4', 'root', '');
$mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tablesToSync = ['tenants', 'roles', 'users', 'products', 'categories', 'customers'];

foreach ($tablesToSync as $table) {
    try {
        $rows = $mysql->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            echo "No data in $table\n";
            continue;
        }

        $columns = array_keys($rows[0]);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $colNames = implode(', ', array_map(fn($c) => "`$c`", $columns));
        
        $sqlite->exec("DELETE FROM `$table`"); // clear before insert
        $stmt = $sqlite->prepare("INSERT INTO `$table` ($colNames) VALUES ($placeholders)");
        
        $count = 0;
        foreach ($rows as $row) {
            $stmt->execute(array_values($row));
            $count++;
        }
        echo "Synced $count rows to $table\n";
    } catch (Exception $e) {
        echo "Error syncing $table: " . $e->getMessage() . "\n";
    }
}
