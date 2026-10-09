<?php
// app/helpers/Branding.php
// Logo rules (single-shop deployment): the login page, dashboards, sidebar
// and catalogue show the logo the owner uploaded in Settings (none until
// one is uploaded). Receipts, invoices and their watermark
// always print the Duaqabe logo (receiptLogo()).
// All paths are resolved through public_url() so they work regardless of
// whether the web server's document root points at the project root or
// straight at the public/ folder (see app/helpers/PathConfig.php).

class Branding
{
    // The Duaqabe logo printed on receipts and invoices (and as their watermark).
    const RECEIPT_LOGO_REL = 'assets/images/logo/duaqabe-general-logo-black.png';

    /** Logo for printed/emailed receipts and invoices: always the Duaqabe logo. */
    public static function receiptLogo(): string
    {
        return public_url(self::RECEIPT_LOGO_REL);
    }

    /** The shop's uploaded logo, or '' when none has been uploaded. */
    public static function loginLogo(): string
    {
        try {
            return self::tenantLogo((new Models\TenantModel(Database::pdo()))->primary());
        } catch (Throwable $e) {
            return '';
        }
    }

    /** This shop's uploaded logo, or '' when none has been uploaded. */
    public static function tenantLogo(?array $tenant): string
    {
        if ($tenant && !empty($tenant['logo_path'])) {
            return public_url(self::normalizeStoredPath($tenant['logo_path']));
        }
        return '';
    }

    /**
     * Older rows (or a differently-configured server) may have a stored path
     * that's already absolute (leading '/', with or without a 'public/'
     * prefix). Strip that down to a path relative to the public root so it
     * can be safely passed through public_url().
     */
    private static function normalizeStoredPath(string $path): string
    {
        $path = ltrim($path, '/');
        if (strpos($path, 'public/') === 0) {
            $path = substr($path, strlen('public/'));
        }
        return $path;
    }
}
