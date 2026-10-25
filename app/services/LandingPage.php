<?php
// app/services/LandingPage.php
// The public landing page at the site root: its editable content, the
// read-only product cards, and visitors' comments and likes.
//
//   LandingPage::content($pdo)          every text/image/toggle, defaults filled in
//   LandingPage::save($pdo, $content)   (owner editor: super/landing/)
//   LandingPage::products($pdo, $c)     the cards shown under "What we sell"
//   LandingPage::comments($pdo)         approved comments, newest first
//
// Content is one JSON value in site_settings ('landing_page'), so adding a
// field later needs no migration — a missing key just takes its default.
// Comments and likes get their own small tables, created on first use.

require_once __DIR__ . '/../models/SettingModel.php';

class LandingPage
{
    private const KEY = 'landing_page';

    /** Every editable field and its starting value. Text uses *word* for the purple italic accent. */
    public static function defaults(string $shopName = 'Duaqabe General Shop'): array
    {
        $short = trim(preg_split('/\s+/', $shopName)[0] ?? $shopName) ?: $shopName;
        return [
            // Hero
            'hero_status'   => 'Open today · Everyday essentials',
            'hero_title'    => 'Welcome to *' . $short . '*, your neighbourhood shop',
            'hero_text_1'   => $shopName . ' brings groceries, household goods and the little things you need every day together under one roof, at fair prices.',
            'hero_text_2'   => 'Pop in, browse what we stock below, and enjoy quick checkout with a clear receipt for every purchase.',
            'hero_button'   => 'See what we sell',
            'hero_image'    => '',   // optional photo on the tablet screen
            'receipt_lines' => "Groceries ..... ✓\nHousehold ..... ✓\nFresh stock ... DAILY\nCheckout ...... FAST",
            'receipt_total' => 'TOTAL: VALUE',
            'receipt_thanks'=> "Thank you for\nshopping with us ♥",
            'tablet_label'  => 'OPEN',
            // What we sell
            'show_products'     => true,
            'products_eyebrow'  => 'What we sell',
            'products_title'    => 'Fresh on our shelves',
            'products_text'     => 'A look at what you\'ll find in store. Prices may change, so ask at the counter for today\'s offers.',
            'products_mode'     => 'latest', // latest | selected
            'products_ids'      => [],
            'products_limit'    => 12,
            'show_prices'       => true,
            'show_stock'        => true,
            'hide_out_of_stock' => false,
            // Shop page (shop/): every active product, with search and filters
            'show_shop_page'  => true,
            'shop_eyebrow'    => 'Our shop',
            'shop_title'      => 'Everything on our *shelves*',
            'shop_text'       => 'Browse all we stock. Prices are what you pay at the counter; ask us about today\'s offers.',
            'shop_per_page'   => 24,
            // Why shop with us
            'why_eyebrow' => 'Why shop with us',
            'why_title'   => 'Every visit, you can count on:',
            'why_items'   => "Fair, clearly marked prices on everything we stock\nShelves kept full, with stock checked every day\nQuick checkout and a clear receipt for every purchase\nFriendly, familiar faces who know their neighbours",
            'why_image'   => '',
            // Promise / quote
            'quote_title' => '"Good service is our everyday promise."',
            'quote_text_1'=> 'We started ' . $shopName . ' to give our neighbourhood a shop it can rely on: honest prices, well-stocked shelves and a warm welcome every time.',
            'quote_text_2'=> 'Thank you for choosing us. We look forward to seeing you again soon.',
            'quote_sign'  => '— The ' . $shopName . ' team',
            'quote_image' => '',
            'allow_likes' => true,
            // Comments
            'allow_comments'   => true,
            'comments_approve' => true,  // new comments wait for the owner's OK
            'comments_title'   => 'What our customers say',
            'comments_text'    => 'Shopped with us? Leave a few words, we read every one.',
            // About
            'about_eyebrow' => 'The shop behind the counter',
            'about_title'   => 'Hello, we\'re ' . $short,
            'about_text_1'  => $shopName . ' is your friendly neighbourhood store for everyday essentials, from groceries and household goods to the little things you didn\'t know you needed.',
            'about_text_2'  => 'Our shop is where neighbours meet, and we want every visit to feel quick, easy and welcoming.',
            'about_text_3'  => '',
            'about_image'   => '',
            'contact_phone'    => '',
            'contact_location' => '',
            'contact_hours'    => 'Mon – Sat · 7:00am – 9:00pm',
            // Sign-in section
            'show_login'    => true,
            'login_title'   => 'Sign in to the POS',
            'login_text'    => 'For the shop team only. Owners and admins use their email and password; staff use their PIN at the till.',
            // Footer
            'footer_text' => 'Serving our neighbourhood every day',
        ];
    }

    /** Field groups the editor treats specially. */
    public const BOOLS = ['show_products', 'show_prices', 'show_stock', 'hide_out_of_stock', 'show_shop_page', 'allow_likes', 'allow_comments', 'comments_approve', 'show_login'];
    public const IMAGES = ['hero_image', 'why_image', 'quote_image', 'about_image'];

    public static function content(PDO $pdo, ?string $shopName = null): array
    {
        $defaults = self::defaults($shopName ?: 'Duaqabe General Shop');
        $saved = [];
        try {
            $saved = json_decode((string) (new SettingModel($pdo))->get(self::KEY, ''), true) ?: [];
        } catch (Throwable $e) {
            error_log('LandingPage: read content: ' . $e->getMessage());
        }
        $c = array_merge($defaults, array_intersect_key($saved, $defaults));
        foreach (self::BOOLS as $k) {
            $c[$k] = (bool) $c[$k];
        }
        $c['products_ids'] = array_values(array_filter(array_map('intval', (array) $c['products_ids'])));
        $c['products_limit'] = max(1, min(48, (int) $c['products_limit']));
        $c['shop_per_page'] = max(6, min(96, (int) $c['shop_per_page']));
        return $c;
    }

    public static function save(PDO $pdo, array $content): bool
    {
        $keep = array_intersect_key($content, self::defaults());
        return (new SettingModel($pdo))->set(self::KEY, json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** "Welcome to *Duaqabe*" → escaped HTML with the starred words in <em>. */
    public static function accent(string $text): string
    {
        $safe = htmlspecialchars($text, ENT_QUOTES);
        return preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $safe);
    }

    /** A stored image path (uploads/landing/x.jpg, or an older absolute one) → its web address. */
    public static function imageUrl(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $path = ltrim($path, '/');
        $base = trim((string) BASE_URL, '/');
        if ($base !== '' && strpos($path, $base . '/') === 0) {
            $path = substr($path, strlen($base) + 1);
        }
        if (strpos($path, 'public/') === 0) {
            $path = substr($path, 7);
        }
        return public_url($path);
    }

    /* ------------------------------------------------------------ products */

    /** Read-only cards: name, category, price (an active offer wins), picture, in stock or not. */
    public static function products(PDO $pdo, int $tenantId, array $c): array
    {
        $where = "p.tenant_id = ? AND p.status = 'active'";
        $params = [$tenantId];
        if ($c['products_mode'] === 'selected') {
            if (!$c['products_ids']) {
                return [];
            }
            $where .= ' AND p.id IN (' . implode(',', array_fill(0, count($c['products_ids']), '?')) . ')';
            $params = array_merge($params, $c['products_ids']);
        }
        if ($c['hide_out_of_stock']) {
            $where .= ' AND p.quantity > 0';
        }
        try {
            $st = $pdo->prepare(
                self::CARD_SELECT . "
                  WHERE $where
               ORDER BY p.id DESC
                  LIMIT " . (int) $c['products_limit']
            );
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('LandingPage: products: ' . $e->getMessage());
            return [];
        }
        $rows = self::decorate($rows);
        if ($c['products_mode'] === 'selected') {
            // Keep the owner's chosen order.
            $order = array_flip($c['products_ids']);
            usort($rows, static fn($a, $b) => ($order[$a['id']] ?? 0) <=> ($order[$b['id']] ?? 0));
        }
        return $rows;
    }

    /** Columns every product card needs. */
    private const CARD_SELECT = "SELECT p.id, p.name, p.description, p.image_path, p.quantity, p.unit,
                        COALESCE(NULLIF(p.retail_price, 0), p.selling_price) AS price,
                        p.offer_price, p.offer_starts_at, p.offer_ends_at, c.name AS category_name
                   FROM products p
              LEFT JOIN categories c ON c.id = p.category_id";

    /** Price (a live offer wins, the normal price becomes was_price), picture address, in stock. */
    private static function decorate(array $rows): array
    {
        $now = date('Y-m-d H:i:s');
        foreach ($rows as &$r) {
            $offer = (float) ($r['offer_price'] ?? 0);
            $live = $offer > 0
                && (empty($r['offer_starts_at']) || $r['offer_starts_at'] <= $now)
                && (empty($r['offer_ends_at']) || $r['offer_ends_at'] >= $now)
                && $offer < (float) $r['price'];
            $r['was_price'] = $live ? (float) $r['price'] : null;
            $r['price'] = $live ? $offer : (float) $r['price'];
            $r['image_url'] = self::imageUrl($r['image_path'] ?? '');
            $r['in_stock'] = (float) $r['quantity'] > 0;
        }
        unset($r);
        return $rows;
    }

    public const SORTS = ['newest' => 'Newest', 'name' => 'Name A–Z', 'price_low' => 'Price: low to high', 'price_high' => 'Price: high to low'];

    /**
     * The shop page: every active product (not just the landing selection),
     * searched, filtered by category, sorted and split into pages.
     *
     * @return array{rows:array,total:int,all:int,page:int,pages:int,categories:array<int,array{name:string,n:int}>}
     */
    public static function catalogue(PDO $pdo, int $tenantId, array $c, string $q = '', string $cat = '', string $sort = 'newest', int $page = 1): array
    {
        $out = ['rows' => [], 'total' => 0, 'all' => 0, 'page' => 1, 'pages' => 1, 'categories' => []];
        $base = "p.tenant_id = ? AND p.status = 'active'" . ($c['hide_out_of_stock'] ? ' AND p.quantity > 0' : '');
        try {
            $st = $pdo->prepare("SELECT c.name, COUNT(*) AS n FROM products p JOIN categories c ON c.id = p.category_id
                                  WHERE $base AND TRIM(c.name) <> '' GROUP BY c.name ORDER BY c.name");
            $st->execute([$tenantId]);
            $out['categories'] = array_map(static fn($r) => ['name' => (string) $r['name'], 'n' => (int) $r['n']], $st->fetchAll(PDO::FETCH_ASSOC));
            $st = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE $base");
            $st->execute([$tenantId]);
            $out['all'] = (int) $st->fetchColumn();

            $where = $base;
            $params = [$tenantId];
            $q = trim(mb_substr($q, 0, 80));
            if ($q !== '') {
                $where .= " AND (p.name LIKE ? ESCAPE '!' OR p.description LIKE ? ESCAPE '!' OR c.name LIKE ? ESCAPE '!')";
                $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
                array_push($params, $like, $like, $like);
            }
            if ($cat !== '') {
                $where .= ' AND c.name = ?';
                $params[] = $cat;
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE ' . $where);
            $st->execute($params);
            $out['total'] = (int) $st->fetchColumn();
            $per = $c['shop_per_page'];
            $out['pages'] = max(1, (int) ceil($out['total'] / $per));
            $out['page'] = max(1, min($page, $out['pages']));
            $order = [
                'name' => 'p.name ASC',
                'price_low' => 'price ASC, p.name ASC',
                'price_high' => 'price DESC, p.name ASC',
            ][$sort] ?? 'p.id DESC';
            $st = $pdo->prepare(self::CARD_SELECT . " WHERE $where ORDER BY $order LIMIT " . (int) $per . ' OFFSET ' . (int) (($out['page'] - 1) * $per));
            $st->execute($params);
            $out['rows'] = self::decorate($st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            error_log('LandingPage: catalogue: ' . $e->getMessage());
        }
        return $out;
    }

    /** Every active product, for the editor's picker. */
    public static function productChoices(PDO $pdo, int $tenantId): array
    {
        try {
            $st = $pdo->prepare("SELECT id, name, image_path, quantity FROM products WHERE tenant_id = ? AND status = 'active' ORDER BY name");
            $st->execute([$tenantId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /* ------------------------------------------------- comments and likes */

    public static function ensureTables(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS landing_comments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, message TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'pending', visitor_hash TEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
                $pdo->exec("CREATE TABLE IF NOT EXISTS landing_likes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, visitor_hash TEXT NOT NULL UNIQUE,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS landing_comments (
                    id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, message TEXT NOT NULL,
                    status ENUM('pending','approved','hidden') NOT NULL DEFAULT 'pending', visitor_hash CHAR(64) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_landing_comments_status (status, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $pdo->exec("CREATE TABLE IF NOT EXISTS landing_likes (
                    id INT AUTO_INCREMENT PRIMARY KEY, visitor_hash CHAR(64) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_landing_likes_visitor (visitor_hash)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
        } catch (Throwable $e) {
            error_log('LandingPage: tables: ' . $e->getMessage());
        }
    }

    /** One anonymous id per browser (a cookie), hashed with the server's secret so it can't be guessed. */
    public static function visitorHash(): string
    {
        $id = (string) ($_COOKIE['lp_visitor'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            $id = bin2hex(random_bytes(16));
            if (!headers_sent()) {
                setcookie('lp_visitor', $id, ['expires' => time() + 31536000 * 2, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
            }
            $_COOKIE['lp_visitor'] = $id;
        }
        return hash('sha256', $id . '|' . __DIR__);
    }

    public static function comments(PDO $pdo, ?string $status = 'approved', int $limit = 30): array
    {
        self::ensureTables($pdo);
        try {
            if ($status === null) {
                $st = $pdo->prepare("SELECT * FROM landing_comments ORDER BY created_at DESC, id DESC LIMIT " . (int) $limit);
                $st->execute();
            } else {
                $st = $pdo->prepare("SELECT * FROM landing_comments WHERE status = ? ORDER BY created_at DESC, id DESC LIMIT " . (int) $limit);
                $st->execute([$status]);
            }
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function pendingCount(PDO $pdo): int
    {
        self::ensureTables($pdo);
        try {
            return (int) $pdo->query("SELECT COUNT(*) FROM landing_comments WHERE status = 'pending'")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** @return array{ok:bool,error?:string,status?:string} */
    public static function addComment(PDO $pdo, array $c, string $name, string $message): array
    {
        if (!$c['allow_comments']) {
            return ['ok' => false, 'error' => 'Comments are closed.'];
        }
        $name = trim(preg_replace('/\s+/', ' ', strip_tags($name)));
        $message = trim(strip_tags($message));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            return ['ok' => false, 'error' => 'Please enter your name (2–60 letters).'];
        }
        if (mb_strlen($message) < 3 || mb_strlen($message) > 600) {
            return ['ok' => false, 'error' => 'Please write a comment of up to 600 characters.'];
        }
        self::ensureTables($pdo);
        $visitor = self::visitorHash();
        try {
            // Slow down floods: one comment a minute, five a day, per browser.
            $st = $pdo->prepare('SELECT created_at FROM landing_comments WHERE visitor_hash = ? ORDER BY id DESC LIMIT 5');
            $st->execute([$visitor]);
            $recent = $st->fetchAll(PDO::FETCH_COLUMN);
            if ($recent && strtotime((string) $recent[0]) > time() - 60) {
                return ['ok' => false, 'error' => 'Thanks! Please wait a minute before posting again.'];
            }
            if (count($recent) >= 5 && strtotime((string) end($recent)) > time() - 86400) {
                return ['ok' => false, 'error' => 'You\'ve left several comments today. Thank you!'];
            }
            $status = $c['comments_approve'] ? 'pending' : 'approved';
            $pdo->prepare('INSERT INTO landing_comments (name, message, status, visitor_hash, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$name, $message, $status, $visitor, date('Y-m-d H:i:s')]);
            return ['ok' => true, 'status' => $status];
        } catch (Throwable $e) {
            error_log('LandingPage: add comment: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save your comment. Please try again.'];
        }
    }

    public static function setCommentStatus(PDO $pdo, int $id, string $status): void
    {
        self::ensureTables($pdo);
        if ($status === 'delete') {
            $pdo->prepare('DELETE FROM landing_comments WHERE id = ?')->execute([$id]);
        } elseif (in_array($status, ['approved', 'hidden', 'pending'], true)) {
            $pdo->prepare('UPDATE landing_comments SET status = ? WHERE id = ?')->execute([$status, $id]);
        }
    }

    public static function likeCount(PDO $pdo): int
    {
        self::ensureTables($pdo);
        try {
            return (int) $pdo->query('SELECT COUNT(*) FROM landing_likes')->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function hasLiked(PDO $pdo): bool
    {
        if (empty($_COOKIE['lp_visitor'])) {
            return false;
        }
        self::ensureTables($pdo);
        try {
            $st = $pdo->prepare('SELECT 1 FROM landing_likes WHERE visitor_hash = ?');
            $st->execute([self::visitorHash()]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Like, or take the like back. @return array{liked:bool,count:int} */
    public static function toggleLike(PDO $pdo): array
    {
        self::ensureTables($pdo);
        $visitor = self::visitorHash();
        $st = $pdo->prepare('SELECT id FROM landing_likes WHERE visitor_hash = ?');
        $st->execute([$visitor]);
        if ($id = $st->fetchColumn()) {
            $pdo->prepare('DELETE FROM landing_likes WHERE id = ?')->execute([$id]);
            $liked = false;
        } else {
            $pdo->prepare('INSERT INTO landing_likes (visitor_hash, created_at) VALUES (?, ?)')->execute([$visitor, date('Y-m-d H:i:s')]);
            $liked = true;
        }
        return ['liked' => $liked, 'count' => self::likeCount($pdo)];
    }
}
