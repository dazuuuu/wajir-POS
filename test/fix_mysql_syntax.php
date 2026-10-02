<?php
$files = [
    '/Users/dazuhub/Projects/Wajir/app/models/StoreProductModel.php',
    '/Users/dazuhub/Projects/Wajir/app/models/ReturnModel.php',
    '/Users/dazuhub/Projects/Wajir/app/models/PurchaseModel.php',
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    
    // Replace SEPARATOR ', ' with , ', '
    $content = str_replace(
        ' SEPARATOR \', \'',
        ', \', \'',
        $content
    );

    // Replace TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM FORMAT(...)))
    // with RTRIM(RTRIM(FORMAT(...), '0'), '.')
    $content = str_replace(
        "TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM FORMAT(sii.quantity, 2)))",
        "RTRIM(RTRIM(FORMAT(sii.quantity, 2), '0'), '.')",
        $content
    );
    
    file_put_contents($file, $content);
    echo "Fixed $file\n";
}
