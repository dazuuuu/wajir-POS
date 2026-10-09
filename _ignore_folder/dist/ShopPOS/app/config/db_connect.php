<?php
// app/config/db_connect.php
// Legacy CMS-side pages (profile/, api/blog/, api/services/, api/gallery/,
// etc.) get their $pdo from here. This used to open its own hardcoded
// mysql:host=localhost / root / (blank password) connection, independent of
// app/config/database.php — meaning it would fail on any real host exactly
// the way the POS side's sqlite misconfiguration did, just silently and
// separately. It now shares the single Database::pdo() connection (and
// picks up the local-dev-vs-production config split in app/config/
// database.php / database.local.php automatically), so there is exactly one
// place credentials are ever set.
require_once __DIR__ . '/../helpers/PathConfig.php';
require_once __DIR__ . '/../helpers/Database.php';

$pdo = Database::pdo();

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
