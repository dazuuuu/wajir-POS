<?php
// app/helpers/ReceiptShare.php
// Sending a receipt to a customer's WhatsApp, the free way: the POS writes
// the message (shop, items, totals, balance, and a link to the receipt) and
// opens WhatsApp — on the phone/PC that's sending, i.e. the shop's own
// WhatsApp — with it ready; one tap on Send delivers it.
//
//   ReceiptShare::data($pdo, 'order'|'sale', $id)   everything the message needs
//   ReceiptShare::link('order', $id)                public receipt address (r/)
//   ReceiptShare::verify('order', $id, $token)      for r/index.php
//   ReceiptShare::phone('0712 345 678')             → '254712345678' (wa.me form)
//   ReceiptShare::shopWhatsapp($pdo)                the shop's number (Settings)
//
// Links are signed with a secret kept in site_settings, so a customer can open
// their own receipt without logging in but can't guess anyone else's.

require_once __DIR__ . '/../models/SettingModel.php';

class ReceiptShare
{
    private const SECRET_KEY = 'receipt_link_secret';
    public const WHATSAPP_KEY = 'shop_whatsapp';

    private static function secret(PDO $pdo): string
    {
        $s = new SettingModel($pdo);
        $secret = (string) $s->get(self::SECRET_KEY, '');
        if (strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            $s->set(self::SECRET_KEY, $secret);
        }
        return $secret;
    }

    public static function token(PDO $pdo, string $type, int $id): string
    {
        return substr(hash_hmac('sha256', $type . ':' . $id, self::secret($pdo)), 0, 24);
    }

    public static function verify(PDO $pdo, string $type, int $id, string $token): bool
    {
        return $id > 0 && in_array($type, ['order', 'sale'], true) && hash_equals(self::token($pdo, $type, $id), $token);
    }

    public static function link(PDO $pdo, string $type, int $id): string
    {
        return app_url('r/') . '?' . http_build_query(['s' => $type === 'sale' ? 's' : 'o', 'id' => $id, 't' => self::token($pdo, $type, $id)]);
    }

    /** A phone number in the form wa.me wants (country code, digits only); '' if it can't be one. Kenya by default. */
    public static function phone(?string $raw): string
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if ($d === '') {
            return '';
        }
        if (strpos($d, '00') === 0) {
            $d = substr($d, 2);                  // 00254… → 254…
        }
        if (strlen($d) === 10 && $d[0] === '0') {
            $d = '254' . substr($d, 1);          // 0712345678 → 254712345678
        } elseif (strlen($d) === 9 && in_array($d[0], ['7', '1'], true)) {
            $d = '254' . $d;                     // 712345678 → 254712345678
        }
        return strlen($d) >= 10 && strlen($d) <= 15 ? $d : '';
    }

    public static function shopWhatsapp(PDO $pdo): string
    {
        try {
            return (string) (new SettingModel($pdo))->get(self::WHATSAPP_KEY, '');
        } catch (Throwable $e) {
            return '';
        }
    }

    /** Nicely written: 254712345678 → +254 712 345 678. */
    public static function pretty(string $digits): string
    {
        $d = self::phone($digits);
        if ($d === '') {
            return $digits;
        }
        return strpos($d, '254') === 0 && strlen($d) === 12
            ? '+254 ' . substr($d, 3, 3) . ' ' . substr($d, 6, 3) . ' ' . substr($d, 9)
            : '+' . $d;
    }

    /**
     * Everything about one receipt, ready for the message and the public page.
     * Rows are read with the sale's own tenant_id, so this works without a login.
     * @return array|null
     */
    public static function data(PDO $pdo, string $type, int $id, ?int $tenantId = null): ?array
    {
        $isSale = $type === 'sale';
        $st = $pdo->prepare($isSale ? 'SELECT * FROM sales WHERE id = ?' : 'SELECT * FROM orders WHERE id = ?');
        $st->execute([$id]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc || ($tenantId !== null && (int) $doc['tenant_id'] !== $tenantId)) {
            return null;
        }
        $tid = (int) $doc['tenant_id'];
        $tenant = $pdo->query('SELECT * FROM tenants WHERE id = ' . $tid)->fetch(PDO::FETCH_ASSOC) ?: [];

        $it = $pdo->prepare('SELECT i.*, p.unit AS product_unit, p.units_per_pack, p.pack_unit, p.pack_price, p.retail_pack_price
                               FROM ' . ($isSale ? 'sale_items' : 'order_items') . ' i
                          LEFT JOIN products p ON p.id = i.product_id AND p.tenant_id = i.tenant_id
                              WHERE i.' . ($isSale ? 'sale_id' : 'order_id') . ' = ? AND i.tenant_id = ? ORDER BY i.id');
        $it->execute([$id, $tid]);
        $returned = [];
        if (!$isSale) {
            try {
                $rq = $pdo->prepare("SELECT source_item_id, SUM(returned_quantity) q FROM product_returns WHERE tenant_id = ? AND source_type = 'order' AND source_id = ? AND undone_at IS NULL GROUP BY source_item_id");
                $rq->execute([$tid, $id]);
                foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) { $returned[(int) $r['source_item_id']] = (float) $r['q']; }
            } catch (Throwable $e) {
            }
        }
        $items = [];
        foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $q = QtyFormat::saleLine($l);
            $ret = !empty($returned[(int) $l['id']]) ? trim(QtyFormat::saleLine(['quantity' => $returned[(int) $l['id']]] + $l)['summary_qty']) . ' returned' : '';
            $items[] = ['name' => (string) $l['product_name'], 'qty' => trim($q['summary_qty']) ?: QtyFormat::display((float) $l['quantity']),
                'note' => trim((in_array($q['price_note'], ['retail box', 'wholesale box'], true) ? $q['price_note'] : '') . ($ret ? ' · ' . $ret : ''), ' ·'),
                'returned' => $ret,
                'price' => QtyFormat::linePrice($l, $q), // one unit as sold, at the sale's price
                'total' => (float) ($l['line_total'] ?? 0)];
        }

        $payments = [];
        try {
            $p = $pdo->prepare($isSale ? 'SELECT amount, method, created_at, NULL AS reference FROM sale_payments WHERE sale_id = ? AND tenant_id = ? ORDER BY id'
                : 'SELECT amount, method, provider, reference, created_at FROM order_payments WHERE order_id = ? AND tenant_id = ? ORDER BY id');
            $p->execute([$id, $tid]);
            $payments = $p->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
        }

        $customer = trim((string) ($isSale ? ($doc['customer_name'] ?? '') : ($doc['table_name'] ?? '')));
        $phone = (string) ($doc['customer_phone'] ?? '');
        if (!$isSale && !empty($doc['customer_id'])) {
            try {
                $c = $pdo->prepare('SELECT name, phone FROM customers WHERE id = ? AND tenant_id = ?');
                $c->execute([(int) $doc['customer_id'], $tid]);
                if ($row = $c->fetch(PDO::FETCH_ASSOC)) {
                    $customer = $customer !== '' ? $customer : (string) $row['name'];
                    $phone = $phone !== '' ? $phone : (string) ($row['phone'] ?? '');
                }
            } catch (Throwable $e) {
            }
        }

        $total = (float) $doc['total'];
        $paid = min((float) ($doc['amount_paid'] ?? 0), $total);
        return [
            'type' => $isSale ? 'sale' : 'order', 'id' => $id, 'tenant' => $tenant,
            'shop' => trim((string) ($tenant['name'] ?? '')) ?: 'Our shop',
            'ref' => (string) (($doc['receipt_number'] ?? '') ?: ($isSale ? 'SALE-' : 'ORD-') . $id),
            'date' => (string) $doc['created_at'],
            'customer' => in_array(mb_strtolower($customer), ['', 'walk-in', 'walkin', 'walk in'], true) ? '' : $customer,
            'phone' => self::phone($phone),
            'items' => $items,
            'subtotal' => (float) ($doc['subtotal'] ?? $total),
            'discount' => (float) ($doc['discount_amount'] ?? 0),
            'charges' => (float) ($doc['additional_charges'] ?? 0),
            'vat' => (float) ($doc['vat_amount'] ?? 0),
            'total' => $total, 'paid' => $paid, 'due' => max(0, round($total - $paid, 2)),
            'due_at' => (string) ($doc['credit_due_at'] ?? ''),
            'status' => (string) ($doc['status'] ?? ''),
            'payments' => $payments,
            'void' => ($doc['status'] ?? '') === 'void' && ($doc['payment_status'] ?? '') !== 'returned',
        ];
    }

    public const TEMPLATE_KEY = 'whatsapp_receipt_template';

    /** Placeholders the message wording can use (super/whatsapp/). */
    public const PLACEHOLDERS = [
        '{shop}' => 'Shop name', '{ref}' => 'Receipt number', '{date}' => 'Date and time of the sale',
        '{customer_line}' => '"Customer: name" (left out for walk-in sales)', '{customer}' => 'Customer name ("Customer" on walk-in sales)',
        '{items}' => 'Every item: quantity × unit price = amount, one per line', '{totals}' => 'Total, then paid and balance (or "Paid in full")',
        '{total}' => 'Total amount', '{balance}' => 'Balance still owed', '{due_date}' => 'Credit due date',
        '{link}' => 'Link to view / download the receipt', '{shop_whatsapp}' => 'The shop\'s WhatsApp number',
    ];

    public static function defaultTemplate(): string
    {
        return "*{shop}*\nReceipt *{ref}* · {date}\n{customer_line}\n\n{items}\n\n{totals}\n\nView or download your receipt:\n{link}\n\nQuestions? WhatsApp us: {shop_whatsapp}\nThank you for shopping with us!";
    }

    public static function template(PDO $pdo): string
    {
        try {
            $t = (string) (new SettingModel($pdo))->get(self::TEMPLATE_KEY, '');
        } catch (Throwable $e) {
            $t = '';
        }
        return trim($t) !== '' ? $t : self::defaultTemplate();
    }

    /** The WhatsApp message: the shop's wording (template) filled in. Plain text with WhatsApp's *bold*. */
    public static function message(PDO $pdo, array $d, ?string $template = null): string
    {
        $cur = trim((string) ($d['tenant']['currency'] ?? '')) ?: 'KES';
        $m = static fn($v) => $cur . ' ' . number_format((float) $v, 2);
        $items = [];
        foreach (array_slice($d['items'], 0, 15) as $i) {
            $items[] = '• ' . $i['name'] . ': ' . $i['qty'] . ' × ' . number_format($i['price'], 2) . ($i['returned'] ? ', ' . $i['returned'] : '') . ' = ' . number_format($i['total'], 2);
        }
        if (count($d['items']) > 15) {
            $items[] = '• … and ' . (count($d['items']) - 15) . ' more (see the receipt)';
        }
        $totals = [];
        if ($d['discount'] > 0.009) {
            $totals[] = 'Discount: ' . $m($d['discount']);
        }
        $totals[] = '*Total: ' . $m($d['total']) . '*';
        $dueDate = $d['due_at'] ? date('j M Y', strtotime($d['due_at'])) : '';
        if ($d['due'] > 0.009) {
            $totals[] = 'Paid: ' . $m($d['paid']);
            $totals[] = '*Balance: ' . $m($d['due']) . '*' . ($dueDate ? ' (due ' . $dueDate . ')' : '');
        } else {
            $totals[] = 'Paid in full ✅';
        }
        $wa = self::shopWhatsapp($pdo);
        $values = [
            '{shop}' => $d['shop'], '{ref}' => $d['ref'], '{date}' => date('j M Y, g:i a', strtotime($d['date'])),
            '{customer_line}' => $d['customer'] !== '' ? 'Customer: ' . $d['customer'] : '', '{customer}' => $d['customer'] !== '' ? $d['customer'] : 'Customer',
            '{items}' => implode("\n", $items), '{totals}' => implode("\n", $totals),
            '{total}' => $m($d['total']), '{balance}' => $m($d['due']), '{due_date}' => $dueDate,
            '{link}' => self::link($pdo, $d['type'], $d['id']), '{shop_whatsapp}' => $wa !== '' ? self::pretty($wa) : '',
        ];
        // A line whose value is missing (walk-in: no customer; no shop number…) is left out whole,
        // so no "Questions? WhatsApp us:" with nothing after it.
        $lines = [];
        foreach (explode("\n", str_replace("\r", '', $template ?? self::template($pdo))) as $line) {
            foreach ($values as $k => $v) {
                if ($v === '' && strpos($line, $k) !== false) {
                    continue 2;
                }
            }
            $lines[] = strtr($line, $values);
        }
        $text = preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));
        return trim($text);
    }

    /** The newest receipt of this shop, for the preview and the sample on super/whatsapp/. */
    public static function latest(PDO $pdo, int $tenantId): ?array
    {
        try {
            $st = $pdo->prepare("SELECT id FROM orders WHERE tenant_id = ? AND status <> 'void' AND total > 0 ORDER BY id DESC LIMIT 1");
            $st->execute([$tenantId]);
            $id = (int) $st->fetchColumn();
            return $id ? self::data($pdo, 'order', $id, $tenantId) : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
