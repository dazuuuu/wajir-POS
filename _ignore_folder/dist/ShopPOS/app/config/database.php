<?php

// app/config/database.php — SHOP COMPUTER install: the POS keeps all its data
// in one local file, data/pos.sqlite, next to the app. No internet, no MySQL.

return [
    'driver' => 'sqlite',
    'sqlite_path' => dirname(__DIR__, 2) . '/data/pos.sqlite',
];
