<?php
// public/api/ping.php — tiny, dependency-free reachability probe.
// No DB, no session, no auth — just "can this browser reach the server at
// all right now?". navigator.onLine alone is unreliable (it can report
// "online" while the server is actually unreachable — captive portals, DNS
// issues, a dead wifi router still handing out a local IP, etc.), so
// connectivity.js polls this endpoint for a real answer instead of trusting
// the browser's own flag alone.
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['ok' => true, 't' => time()]);
