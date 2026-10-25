<?php
// public/api/sync/push.php — shop PC only: send what changed here to the
// website now (app/helpers/DesktopSync.php). Called every few minutes by the
// open POS pages (components/pwa_head.php) and by "Sync now" on Backups.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth();

header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!DesktopSync::configured()) {
    echo json_encode(['ok' => false, 'sent' => 0, 'error' => 'Sync is not set up on this computer.']);
    exit;
}
session_write_close(); // a long sync mustn't hold up the other pages
// ?status=1: just report (the status pill on every page), no sending.
$r = empty($_GET['status']) ? DesktopSync::push() : ['ok' => true, 'sent' => 0, 'error' => null];
echo json_encode($r + ['status' => DesktopSync::status()]);
