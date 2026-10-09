<?php
// public/api/offline/sales.php — sales history for the device's offline copy
// (assets/js/offline-data.js). Owner gets every sale; staff get their own.
//
// GET ?since=YYYY-mm-dd HH:ii:ss  only orders created/changed since then
//     ?after_id=N                 paging: orders with id > N (500 per page)
//     ?legacy=1                   include the old `sales` table (first sync)
// Every success/error path in this app redirects on success, but this is a
// JSON API: it never redirects except PageGuard's login bounce, which the
// client detects and treats as "not logged in".
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth();

header('Content-Type: application/json');
header('Cache-Control: no-store');

$pageSize = 500;

try {
    $pdo = Database::pdo();
    new Models\OrderModel($pdo); // makes sure the payment/VAT columns exist before SELECT *
    $tid = (int) TenantContext::tenantId();
    $isStaff = TenantContext::role() === 'staff';
    $uid = (int) TenantContext::userId();
    $since = trim((string) ($_GET['since'] ?? ''));
    $afterId = max(0, (int) ($_GET['after_id'] ?? 0));
    $now = $pdo->query('SELECT NOW()')->fetchColumn();

    $where = ['o.tenant_id = ?', 'o.id > ?'];
    $params = [$tid, $afterId];
    if ($since !== '' && strtotime($since) !== false) {
        $where[] = '(o.updated_at >= ? OR o.created_at >= ?)';
        $params[] = $since;
        $params[] = $since;
    }
    if ($isStaff) {
        $where[] = 'o.opened_by = ?';
        $params[] = $uid;
    }
    $st = $pdo->prepare(
        'SELECT o.*, u.username AS staff_name
           FROM orders o
      LEFT JOIN users u ON u.id = o.opened_by
          WHERE ' . implode(' AND ', $where) . '
       ORDER BY o.id ASC
          LIMIT ' . $pageSize
    );
    $st->execute($params);
    $rows = $st->fetchAll();

    $itemsBy = [];
    if ($rows) {
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $it = $pdo->prepare("SELECT order_id, product_id, product_name, quantity, unit_price, line_total, price_type FROM order_items WHERE tenant_id = ? AND order_id IN ($in) ORDER BY id ASC");
        $it->execute(array_merge([$tid], $ids));
        foreach ($it->fetchAll() as $item) {
            $itemsBy[(int) $item['order_id']][] = [
                'pid' => $item['product_id'] !== null ? (int) $item['product_id'] : null,
                'name' => (string) $item['product_name'],
                'qty' => (float) $item['quantity'],
                'price' => (float) $item['unit_price'],
                'total' => (float) $item['line_total'],
                'type' => (string) ($item['price_type'] ?? 'retail'),
            ];
        }
    }

    $num = static fn($row, $k) => isset($row[$k]) ? (float) $row[$k] : 0.0;
    $orders = array_map(static function ($o) use ($itemsBy, $num) {
        $id = (int) $o['id'];
        $total = $num($o, 'total');
        $paid = $num($o, 'amount_paid');
        if ($paid <= 0 && ($o['status'] ?? '') === 'paid') { $paid = $total; }
        return [
            'key' => 'o:' . $id,
            'id' => $id,
            'receipt' => (string) ($o['receipt_number'] ?? ''),
            'customer' => (string) ($o['table_name'] ?? ''),
            'phone' => (string) ($o['customer_phone'] ?? ''),
            'status' => (string) ($o['status'] ?? ''),
            'channel' => (string) ($o['channel'] ?? ''),
            'method' => (string) ($o['payment_method'] ?? ''),
            'subtotal' => $num($o, 'subtotal'),
            'discount' => $num($o, 'discount_amount'),
            'vat' => $num($o, 'vat_amount'),
            'extra' => $num($o, 'additional_charges'),
            'total' => $total,
            'paid' => $paid,
            'due' => max(0, round($total - $paid, 2)),
            'tendered' => $num($o, 'amount_tendered'),
            'change' => $num($o, 'change_due'),
            'staff' => (string) ($o['staff_name'] ?? ''),
            'created_at' => (string) ($o['created_at'] ?? ''),
            'paid_at' => (string) ($o['paid_at'] ?? ''),
            'items' => $itemsBy[$id] ?? [],
        ];
    }, $rows);

    // Old direct sales (before tabs/invoices existed): read-only history,
    // sent once on the first full sync.
    $legacy = [];
    if (!empty($_GET['legacy']) && $afterId === 0) {
        try {
            $sw = $isStaff ? ' AND s.staff_id = ' . $uid : '';
            $ls = $pdo->prepare("SELECT s.*, u.username AS staff_name FROM sales s LEFT JOIN users u ON u.id = s.staff_id WHERE s.tenant_id = ?$sw ORDER BY s.id ASC");
            $ls->execute([$tid]);
            $lrows = $ls->fetchAll();
            $litems = [];
            if ($lrows) {
                $li = $pdo->prepare('SELECT sale_id, product_id, product_name, quantity, unit_price, line_total, price_type FROM sale_items WHERE tenant_id = ? ORDER BY id ASC');
                $li->execute([$tid]);
                foreach ($li->fetchAll() as $item) {
                    $litems[(int) $item['sale_id']][] = ['pid' => $item['product_id'] !== null ? (int) $item['product_id'] : null, 'name' => (string) $item['product_name'], 'qty' => (float) $item['quantity'], 'price' => (float) $item['unit_price'], 'total' => (float) $item['line_total'], 'type' => (string) ($item['price_type'] ?? 'retail')];
                }
            }
            foreach ($lrows as $s) {
                $sid = (int) $s['id'];
                $legacy[] = [
                    'key' => 's:' . $sid, 'id' => $sid, 'receipt' => (string) $s['receipt_number'], 'customer' => (string) ($s['customer_name'] ?? ''),
                    'phone' => (string) ($s['customer_phone'] ?? ''), 'status' => (string) ($s['status'] ?? ''), 'channel' => 'legacy',
                    'method' => (string) ($s['payment_method'] ?? ''), 'subtotal' => $num($s, 'subtotal'), 'discount' => $num($s, 'discount_amount'),
                    'vat' => 0.0, 'extra' => 0.0, 'total' => $num($s, 'total'), 'paid' => $num($s, 'amount_paid'), 'due' => $num($s, 'amount_due'),
                    'tendered' => $num($s, 'amount_given'), 'change' => $num($s, 'change_given'), 'staff' => (string) ($s['staff_name'] ?? ''),
                    'created_at' => (string) $s['created_at'], 'paid_at' => '', 'items' => $litems[$sid] ?? [],
                ];
            }
        } catch (Throwable $ignored) {
            // No legacy table on this install — nothing to send.
        }
    }

    $shop = [];
    if ($afterId === 0) {
        $t = (new Models\TenantModel($pdo))->find($tid) ?: [];
        $shop = [
            'name' => (string) ($t['name'] ?? ''), 'address' => (string) ($t['address'] ?? ''), 'phone' => (string) ($t['phone'] ?? ''),
            'po_box' => (string) ($t['po_box'] ?? ''), 'email' => (string) ($t['business_email'] ?? ''), 'kra_pin' => (string) ($t['kra_pin'] ?? ''),
            'currency' => (string) ($t['currency'] ?? 'KES'), 'footer' => (string) ($t['receipt_footer'] ?? ''),
        ];
    }

    echo json_encode([
        'ok' => true,
        'now' => $now,
        'orders' => $orders,
        'legacy' => $legacy,
        'shop' => $shop,
        'next_after_id' => count($rows) === $pageSize ? (int) end($rows)['id'] : null,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not export sales for offline use.']);
}
