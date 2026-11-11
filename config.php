<?php
// Set email provider credentials as environment variables or in a local-only mail.local.php.
if (is_file(__DIR__ . '/mail.local.php')) { require_once __DIR__ . '/mail.local.php'; }

// Load Composer dependencies.
require_once __DIR__ . '/vendor/autoload.php';

// On a live host (e.g. InfinityFree) copy db.local.php.example to db.local.php and fill in
// your hosting database details. db.local.php is never committed to GitHub.
if (is_file(__DIR__ . '/db.local.php')) { require_once __DIR__ . '/db.local.php'; }
defined('DB_HOST') || define('DB_HOST', getenv('DB_HOST') ?: (getenv('MYSQLHOST') ?: '127.0.0.1'));
defined('DB_PORT') || define('DB_PORT', getenv('DB_PORT') ?: (getenv('MYSQLPORT') ?: '3306'));
defined('DB_NAME') || define('DB_NAME', getenv('DB_NAME') ?: (getenv('MYSQLDATABASE') ?: 'andys_store'));
defined('DB_USER') || define('DB_USER', getenv('DB_USER') ?: (getenv('MYSQLUSER') ?: 'root'));
defined('DB_PASS') || define('DB_PASS', getenv('DB_PASS') ?: (getenv('MYSQLPASSWORD') ?: ''));

if (session_status() === PHP_SESSION_NONE) session_start();

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        
        try { $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'location_address'")->fetchAll(); if (!$cols) $pdo->exec('ALTER TABLE users ADD COLUMN location_address VARCHAR(255) NULL AFTER phone'); } catch (Throwable $ignored) {}
        
        $accountStatusColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'account_status'")->fetch();
        if (!$accountStatusColumn) {
            $pdo->exec("ALTER TABLE users ADD COLUMN account_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER role");
        }
        
        $emailVerifiedColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_verified_at'")->fetch();
        if (!$emailVerifiedColumn) {
            $pdo->exec('ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER email');
            $pdo->exec("UPDATE users SET email_verified_at=UTC_TIMESTAMP() WHERE role='customer' AND account_status='approved'");
        }

        if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'school_type'")->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN school_type ENUM('university','lhs') NOT NULL DEFAULT 'university' AFTER phone");
        }
        if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'strand'")->fetch()) {
            $pdo->exec('ALTER TABLE users ADD COLUMN strand VARCHAR(40) DEFAULT NULL AFTER year_level');
        }

        $priceOptionsExisted = (bool) $pdo->query("SHOW TABLES LIKE 'product_options'")->fetchColumn();
        $pdo->exec("CREATE TABLE IF NOT EXISTS product_options (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            option_name VARCHAR(80) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            UNIQUE KEY uq_product_option (product_id, option_name),
            CONSTRAINT fk_product_options_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        if (!$priceOptionsExisted) {
            $pdo->exec("INSERT INTO product_options(product_id,option_name,price)
                SELECT p.id, 'Meal with rice', p.price
                FROM products p JOIN categories c ON c.id=p.category_id
                WHERE c.slug='meals'");
            $pdo->exec("INSERT INTO product_options(product_id,option_name,price)
                SELECT p.id, 'Meal only', p.price
                FROM products p JOIN categories c ON c.id=p.category_id
                WHERE c.slug='meals'");
        }
        
        $pdo->exec("UPDATE users SET account_status='approved' WHERE role='customer' AND account_status<>'approved'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_codes (
            user_id BIGINT UNSIGNED PRIMARY KEY,
            token_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        
        $resetRequestedColumn = $pdo->query("SHOW COLUMNS FROM password_reset_codes LIKE 'requested_at'")->fetch();
        if (!$resetRequestedColumn) {
            $pdo->exec('ALTER TABLE password_reset_codes ADD COLUMN requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        }
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_verification_tokens (
            user_id BIGINT UNSIGNED PRIMARY KEY,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        
        $verificationAttemptsColumn = $pdo->query("SHOW COLUMNS FROM email_verification_tokens LIKE 'attempts'")->fetch();
        if (!$verificationAttemptsColumn) {
            $pdo->exec('ALTER TABLE email_verification_tokens ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER expires_at');
        }
    }
    return $pdo;
}

function mail_settings(): array {
    $provider = strtolower(trim((string) (getenv('MAIL_PROVIDER') ?: 'resend')));
    if (!in_array($provider, ['resend', 'brevo'], true)) {
        throw new RuntimeException('Email provider is invalid. Set MAIL_PROVIDER to resend or brevo.');
    }

    $apiKeyVariable = $provider === 'brevo' ? 'BREVO_API_KEY' : 'RESEND_API_KEY';
    $fromEmailVariable = $provider === 'brevo' ? 'BREVO_FROM_EMAIL' : 'RESEND_FROM_EMAIL';
    $apiKey = trim((string) getenv($apiKeyVariable));
    $fromEmail = trim((string) getenv($fromEmailVariable));
    if ($apiKey === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("Email delivery is not configured. Set {$apiKeyVariable} and {$fromEmailVariable}.");
    }
    return [
        'provider' => $provider,
        'api_key' => $apiKey,
        'from_email' => $fromEmail,
    ];
}

function send_account_email(string $to, string $name, string $subject, string $htmlBody, string $textBody): void {
    $cfg = mail_settings();
    if ($cfg['provider'] === 'brevo') {
        $url = 'https://api.brevo.com/v3/smtp/email';
        $payload = json_encode([
            'sender' => ['name' => "Andy's Store", 'email' => $cfg['from_email']],
            'to' => [['email' => $to, 'name' => $name]],
            'subject' => $subject,
            'htmlContent' => $htmlBody,
        ], JSON_THROW_ON_ERROR);
        $authorizationHeader = 'api-key: ' . $cfg['api_key'];
    } else {
        $url = 'https://api.resend.com/emails';
        $payload = json_encode([
            'from' => "Andy's Store <{$cfg['from_email']}>",
            'to' => [$to],
            'subject' => $subject,
            'html' => $htmlBody,
            'text' => $textBody,
        ], JSON_THROW_ON_ERROR);
        $authorizationHeader = 'Authorization: Bearer ' . $cfg['api_key'];
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Could not initialize the email API request.');
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            $authorizationHeader,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($response === false) {
        error_log('Andys Store email API request failed: ' . $curlError);
        throw new RuntimeException('Could not send the email. Check the email provider API connection.');
    }

    if ($status < 200 || $status >= 300) {
        $responseData = json_decode($response, true);
        $errorCode = is_array($responseData)
            ? (string) ($responseData['name'] ?? $responseData['code'] ?? 'unknown')
            : 'unknown';
        error_log("Andys Store {$cfg['provider']} email API returned HTTP {$status} ({$errorCode}).");
        throw new RuntimeException('Could not send the email. Check the email API key, verified sender address, and recipient.');
    }
}

/**
 * Sends a test PIN through the configured email API.
 */
function send_pin_email(string $toEmail, string $toName, string $pin): array {
    $subject = "Your Verification PIN";
    
    $htmlBody = "
        <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto;'>
            <h2>Hello {{name}},</h2>
            <p>You requested a verification PIN. Please use the code below to proceed:</p>
            <div style='background: #f4f4f4; padding: 20px; letter-spacing: 8px; text-align: center; font-size: 32px; font-weight: bold; border-radius: 8px; margin: 20px 0;'>
                " . e($pin) . "
            </div>
            <p style='color: #666; font-size: 14px;'>This PIN will expire in 10 minutes. If you did not request this PIN, please ignore this email.</p>
        </div>
    ";
    
    $altBody = "Hello {{name}},\n\nYour verification PIN is: " . $pin . "\n\nThis PIN will expire in 10 minutes. If you did not request this, please ignore this email.";

    try {
        send_account_email($toEmail, $toName, $subject, $htmlBody, $altBody);
        return ['success' => true, 'message' => 'Email successfully sent.'];
    } catch (Throwable $ex) {
        return ['success' => false, 'message' => $ex->getMessage()];
    }
}

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function get_product_options(PDO $pdo, int $productId): array {
    $stmt = $pdo->prepare('SELECT option_name,price FROM product_options WHERE product_id=? ORDER BY id');
    $stmt->execute([$productId]);
    return $stmt->fetchAll();
}

function ensure_product_images_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_images (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id bigint(20) UNSIGNED NOT NULL,
        image_url varchar(500) NOT NULL,
        sort_order tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY fk_product_images_product (product_id),
        CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT INTO product_images(product_id,image_url,sort_order)
        SELECT p.id,p.image_url,0
        FROM products p
        LEFT JOIN product_images pi
          ON pi.product_id=p.id AND pi.image_url=p.image_url
        WHERE p.image_url IS NOT NULL AND p.image_url<>''
          AND pi.id IS NULL");
}

function get_product_images(PDO $pdo, int $productId): array {
    $stmt = $pdo->prepare('SELECT id,image_url FROM product_images WHERE product_id=? ORDER BY sort_order,id');
    $stmt->execute([$productId]);
    // Safety net: never show the same image file twice for one product.
    $seen = [];
    $unique = [];
    foreach ($stmt->fetchAll() as $row) {
        if (isset($seen[$row['image_url']])) continue;
        $seen[$row['image_url']] = true;
        $unique[] = $row;
    }
    return $unique;
}

function parse_product_flavors(string $raw, float $price): array {
    $options = [];
    $names = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($raw)) ?: [] as $line) {
        $name = trim($line);
        if ($name === '') {
            continue;
        }
        $nameKey = strtolower($name);
        if (strlen($name) > 80 || isset($names[$nameKey])) {
            throw new InvalidArgumentException('Flavor names must be unique and 80 characters or fewer.');
        }
        $names[$nameKey] = true;
        $options[] = ['name' => $name, 'price' => $price];
    }

    return $options;
}

function parse_product_options(string $raw, string $categorySlug): array {
    if ($categorySlug !== 'meals') {
        return [];
    }

    $options = [];
    $names = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($raw)) ?: [] as $line) {
        if (trim($line) === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line, 2));
        if (count($parts) !== 2 || $parts[0] === '' || strlen($parts[0]) > 80 || !preg_match('/^\d+(?:\.\d{1,2})?$/', $parts[1])) {
            throw new InvalidArgumentException('Enter each price option on its own line as Name | Price.');
        }
        $nameKey = strtolower($parts[0]);
        $price = (float) $parts[1];
        if (isset($names[$nameKey]) || $price <= 0) {
            throw new InvalidArgumentException('Price option names must be unique and prices must be greater than zero.');
        }
        $names[$nameKey] = true;
        $options[] = ['name' => $parts[0], 'price' => $price];
    }

    if (!$options) {
        throw new InvalidArgumentException('Add at least one price option for meals.');
    }
    if ($categorySlug === 'meals' && (!isset($names['meal with rice']) || !isset($names['meal only']))) {
        throw new InvalidArgumentException('Meals must include both "Meal with rice" and "Meal only" price options.');
    }
    return $options;
}

function current_user(): ?array { return $_SESSION['user'] ?? null; }
function redirect(string $location): void {
    header('Location: ' . $location);
    exit;
}
function require_admin(): void {
    if (!current_user() || current_user()['role'] !== 'admin') {
        redirect('index.php?page=admin');
    }
}
function require_customer(): void {
    if (!current_user() || current_user()['role'] !== 'customer') {
        redirect('index.php?page=login');
    }
    $stmt = db()->prepare("SELECT account_status,email_verified_at FROM users WHERE id=? AND role='customer' LIMIT 1");
    $stmt->execute([current_user()['id']]);
    $account = $stmt->fetch();
    if (!$account || !$account['email_verified_at'] || $account['account_status'] === 'rejected') {
        unset($_SESSION['user']);
        $message = $account && $account['account_status'] === 'rejected'
            ? 'Your account access has been revoked. Contact the store administrator for help.'
            : 'Verify your email address before using your account.';
        flash($message);
        redirect('index.php?page=login');
    }
}
function flash(string $message): void { $_SESSION['flash'] = $message; }