<?php
// Script to test all models by instantiating them and calling a basic method to trigger SQL parsing.
require_once __DIR__ . '/../app/helpers/Database.php';

// Mock TenantContext
class TenantContext {
    public static function tenantId() { return 1000; }
    public static function get() { return (object)['id' => 1000]; }
}
// Mock Auth
class Auth {
    public static function user() { return (object)['id' => 1, 'role' => 'staff']; }
    public static function hasPermission() { return true; }
}

$files = glob(__DIR__ . '/../app/models/*Model.php');
foreach ($files as $file) {
    require_once $file;
    $class = 'Models\\' . basename($file, '.php');
    if (class_exists($class)) {
        echo "Testing $class...\n";
        try {
            $model = new $class();
            // Just creating the model tests if the constructor has any immediate queries
            // We can also try calling a generic get method if it exists
            if (method_exists($model, 'all')) {
                $model->all();
            }
        } catch (Throwable $e) {
            echo "Error in $class: " . $e->getMessage() . "\n";
        }
    }
}
echo "Done.\n";
