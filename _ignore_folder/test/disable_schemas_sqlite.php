<?php
$files = glob(__DIR__ . '/../app/models/*Model.php');
$target = "    private function ensureSchema(): void\n    {";
$replacement = "    private function ensureSchema(): void\n    {\n        if (\$this->db->getAttribute(\\PDO::ATTR_DRIVER_NAME) === 'sqlite') { return; }";

foreach ($files as $file) {
    $content = file_get_contents($file);
    
    // Check if ensureSchema exists
    if (strpos($content, $target) !== false) {
        // Only replace if not already replaced
        if (strpos($content, "getAttribute(\\PDO::ATTR_DRIVER_NAME) === 'sqlite'") === false) {
            $content = str_replace($target, $replacement, $content);
            file_put_contents($file, $content);
            echo "Updated $file\n";
        }
    }
}
echo "Done.\n";
