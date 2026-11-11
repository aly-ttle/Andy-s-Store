<?php
// Email uses PHPMailer with Gmail SMTP. Credentials are NOT stored in this file.
// Set ANDYS_GMAIL_ADDRESS and ANDYS_GMAIL_APP_PASSWORD as environment variables,
// or (easier on XAMPP) copy mail.local.php.example to mail.local.php and fill it in.
if (is_file(__DIR__ . '/mail.local.php')) { require_once __DIR__ . '/mail.local.php'; }

// Install Composer dependencies and load local-only overrides when present.
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/EmailService.php';

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
    $username = trim((string) getenv('ANDYS_GMAIL_ADDRESS'));
    $appPassword = str_replace(' ', '', (string) getenv('ANDYS_GMAIL_APP_PASSWORD'));
    if ($username === '' || $appPassword === '' || !filter_var($username, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Email delivery is not configured. Set ANDYS_GMAIL_ADDRESS and ANDYS_GMAIL_APP_PASSWORD (see mail.local.php.example).');
    }
    return [
        'host'       => 'smtp.gmail.com',
        'username'   => $username,
        'password'   => $appPassword,
        'port'       => 587,
        'from_email' => $username,
        'from_name'  => "Andy's Store",
    ];
}

function send_account_email(string $to, string $name, string $subject, string $htmlBody, string $textBody): void {
    $cfg = mail_settings();
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->SMTPDebug = 0;
        $mail->Debugoutput = 'error_log';
        $mail->Host = $cfg['host'];
        $mail->Timeout = 10;
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['username'];
        $mail->Password = $cfg['password'];
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $cfg['port'];
        $mail->CharSet = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to, $name);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;
        $mail->send();
    } catch (Throwable $ex) {
        error_log('Andys Store email delivery failed: ' . $mail->ErrorInfo);
        throw new RuntimeException('Could not send the email. Check the Gmail address, app password, and SMTP connection.');
    }
}

/**
 * Sends a PIN to a user's email using the EmailService class.
 */
function send_pin_email(string $toEmail, string $toName, string $pin): array {
    try {
        $smtpConfig = mail_settings();
    } catch (RuntimeException $ex) {
        return ['success' => false, 'message' => $ex->getMessage()];
    }

    $emailService = new EmailService($smtpConfig);

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

    return $emailService->sendToUser($toEmail, $toName, $subject, $htmlBody, $altBody);
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
    if (!$account || !$account['email_verified_at']) {
        unset($_SESSION['user']);
        $message = 'Verify your email address before using your account.';
        flash($message);
        redirect('index.php?page=login');
    }
}
function flash(string $message): void { $_SESSION['flash'] = $message; }