<?php
$files = [
    '/Users/dazuhub/Projects/Wajir/app/models/FinanceModel.php',
    '/Users/dazuhub/Projects/Wajir/app/models/SaleModel.php',
    '/Users/dazuhub/Projects/Wajir/app/models/OrderModel.php',
    '/Users/dazuhub/Projects/Wajir/app/models/StoreProductModel.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    
    // Replace DATE_SUB(CURDATE(), INTERVAL 7 DAY) -> '" . date('Y-m-d', strtotime('-7 days')) . "'
    $content = str_replace(
        'DATE_SUB(CURDATE(), INTERVAL 7 DAY)',
        '\'" . date(\'Y-m-d\', strtotime(\'-7 days\')) . "\'',
        $content
    );
    
    // Replace DATE_SUB(CURDATE(), INTERVAL 30 DAY) -> '" . date('Y-m-d', strtotime('-30 days')) . "'
    $content = str_replace(
        'DATE_SUB(CURDATE(), INTERVAL 30 DAY)',
        '\'" . date(\'Y-m-d\', strtotime(\'-30 days\')) . "\'',
        $content
    );
    
    // Replace DATE_SUB(NOW(), INTERVAL 7 DAY) -> '" . date('Y-m-d H:i:s', strtotime('-7 days')) . "'
    $content = str_replace(
        'DATE_SUB(NOW(), INTERVAL 7 DAY)',
        '\'" . date(\'Y-m-d H:i:s\', strtotime(\'-7 days\')) . "\'',
        $content
    );
    
    // Replace DATE_SUB(NOW(), INTERVAL 30 DAY) -> '" . date('Y-m-d H:i:s', strtotime('-30 days')) . "'
    $content = str_replace(
        'DATE_SUB(NOW(), INTERVAL 30 DAY)',
        '\'" . date(\'Y-m-d H:i:s\', strtotime(\'-30 days\')) . "\'',
        $content
    );
    
    file_put_contents($file, $content);
    echo "Fixed $file\n";
}
