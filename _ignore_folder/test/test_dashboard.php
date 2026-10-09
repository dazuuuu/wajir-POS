<?php
$cookieJar = __DIR__ . '/test_cookies_2.txt';
if (file_exists($cookieJar)) unlink($cookieJar);

// 1. Login
$ch = curl_init('http://localhost:8080/auth/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'email' => 'dazuhubs@gmail.com',
    'password' => '1234'
]));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($httpCode !== 200 && $httpCode !== 302) {
    echo "Login failed. Code: $httpCode. Error: " . curl_error($ch) . "\n";
    exit;
}
echo "Login OK. Code: $httpCode\n";

// 2. Fetch some key pages
$pages = [
    'http://localhost:8080/staff/dashboard/index.php',
    'http://localhost:8080/staff/sales/index.php',
    'http://localhost:8080/staff/orders/index.php',
    'http://localhost:8080/staff/inventory/index.php',
    'http://localhost:8080/staff/suppliers/index.php',
    'http://localhost:8080/staff/returns/index.php',
    'http://localhost:8080/staff/documents/index.php',
];

foreach ($pages as $page) {
    curl_setopt($ch, CURLOPT_URL, $page);
    curl_setopt($ch, CURLOPT_POST, false);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode === 200) {
        if (strpos($response, 'Fatal error') !== false || strpos($response, 'PDOException') !== false) {
            echo "FAIL: $page returned 200 but contains Fatal error / PDOException!\n";
            // Extract the error message
            preg_match('/(?:Fatal error|PDOException).*$/m', $response, $matches);
            if ($matches) echo "  => " . $matches[0] . "\n";
        } else {
            echo "PASS: $page returned 200\n";
        }
    } else {
        echo "FAIL: $page returned $httpCode. Error: " . curl_error($ch) . "\n";
    }
}
curl_close($ch);
