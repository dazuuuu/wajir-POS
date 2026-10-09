<?php
// Default + formatted receipt closing block for printed/emailed receipts.

class ReceiptFooter
{
    // No built-in shop details: everything printed comes from Settings.
    // These are only the fallbacks for fields left empty there.
    public const SHOP_NAME = 'My Shop';
    public const SHOP_BOX = '';
    public const SHOP_LOCATION = '';
    public const SHOP_EMAIL = '';
    public const SHOP_PHONE = '';

    public const DEFAULT_LINES = [
        'GOODS ONCE SOLD ARE NOT REACCEPTED',
        '!THANK YOU FOR VISITING OUR BUSINESS!',
        '!!WELCOME!!',
    ];

    /** Default footer for this shop: its own email/phone from Settings, then the closing lines. */
    public static function defaultText(?array $tenant): string
    {
        $contact = array_values(array_filter([
            trim((string) ($tenant['business_email'] ?? '')),
            trim((string) ($tenant['phone'] ?? '')),
        ]));
        $lines = $contact ? array_merge(['For questions and enquiries:'], $contact) : [];
        return implode("\n", array_merge($lines, self::DEFAULT_LINES));
    }

    /** Resolve footer text from tenant settings (or the shop default). */
    public static function text(?array $tenant): string
    {
        $custom = trim((string) ($tenant['receipt_footer'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        return self::defaultText($tenant);
    }

    /** One contact detail of the shop (for the public pages): phone, business_email, address. */
    public static function contact(string $field): string
    {
        try {
            $t = (new Models\TenantModel(Database::pdo()))->primary();
            return trim((string) ($t[$field] ?? ''));
        } catch (Throwable $e) {
            return '';
        }
    }

    /** HTML block for thermal / email receipts. */
    public static function html(?array $tenant, ?string $invoiceNumber = null): string
    {
        $h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $lines = preg_split("/\r\n|\n|\r/", self::text($tenant)) ?: [];
        $parts = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts[] = '<div style="font-size:11px;letter-spacing:.02em;margin:2px 0;">' . $h($line) . '</div>';
        }
        return '<div style="text-align:center;color:#64748b;margin-top:14px;border-top:1px dashed #cbd5e1;padding-top:10px;">'
            . implode('', $parts) . '</div>';
    }
}
