<?php
require __DIR__.'/config.php';

$page = $_GET['page'] ?? 'home';
$adminSection = $_GET['section'] ?? 'dashboard';
if (!in_array($adminSection, ['dashboard', 'orders', 'products', 'customers', 'reports', 'settings'], true)) {
  $adminSection = 'dashboard';
}
$error = '';

function save_product_images(): array {
  $uploads = $_FILES['images'] ?? null;
  if (!$uploads || !isset($uploads['name']) || !is_array($uploads['name'])) {
    return [];
  }

  $files = [];
  foreach ($uploads['name'] as $index => $name) {
    $uploadError = $uploads['error'][$index] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
      continue;
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
      throw new Exception('Product image upload failed.');
    }

    $temporaryPath = $uploads['tmp_name'][$index] ?? '';
    if (($uploads['size'][$index] ?? 0) > 5 * 1024 * 1024) {
      throw new Exception('Product images must be 5 MB or smaller.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$extension || !getimagesize($temporaryPath)) {
      throw new Exception('Use valid JPG, PNG, or WEBP product images.');
    }
    $files[] = ['temporary_path' => $temporaryPath, 'extension' => $extension];
  }

  if (count($files) > 6) {
    throw new Exception('Choose no more than 6 product images.');
  }

  if (!$files) {
    return [];
  }

  $directory = __DIR__ . '/products';
  if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    throw new Exception('Could not create the product image folder.');
  }

  $savedImages = [];
  try {
    foreach ($files as $file) {
      $filename = 'product_' . bin2hex(random_bytes(12)) . '.' . $file['extension'];
      if (!move_uploaded_file($file['temporary_path'], $directory . '/' . $filename)) {
        throw new Exception('Could not save a product image.');
      }
      $savedImages[] = 'products/' . $filename;
    }
  } catch (Throwable $ex) {
    foreach ($savedImages as $imageUrl) {
      $savedPath = __DIR__ . '/' . $imageUrl;
      if (is_file($savedPath) && !unlink($savedPath)) {
        error_log('Unable to remove a product image after an incomplete upload.');
      }
    }
    throw $ex;
  }

  return $savedImages;
}

function is_strong_password(string $password): bool {
  return strlen($password) >= 8
    && preg_match('/[a-z]/', $password) === 1
    && preg_match('/[A-Z]/', $password) === 1
    && preg_match('/[\x21-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E]/', $password) === 1;
}

function send_password_reset_pin(PDO $pdo, array $customer): void {
  $pin = strtoupper(bin2hex(random_bytes(8)));
  $pdo->prepare('INSERT INTO password_reset_codes(user_id,token_hash,expires_at,attempts,requested_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE),0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),expires_at=VALUES(expires_at),attempts=0,requested_at=VALUES(requested_at)')
    ->execute([$customer['id'], password_hash($pin, PASSWORD_DEFAULT)]);
  try {
    $safeName = e($customer['name']);
    $safePin = e($pin);
    send_account_email(
      $customer['email'],
      $customer['name'],
      "Your Andy's Store password reset PIN",
      "<p>Hello {$safeName},</p><p>Your password reset PIN is:</p><p style=\"font-size:24px;font-weight:bold;letter-spacing:3px\">{$safePin}</p><p>This PIN expires in 15 minutes. If you did not request it, you can ignore this email.</p>",
      "Hello {$customer['name']},\n\nYour password reset PIN is: {$pin}\n\nIt expires in 15 minutes. If you did not request it, ignore this email."
    );
  } catch (Throwable $ex) {
    $pdo->prepare('DELETE FROM password_reset_codes WHERE user_id=?')->execute([$customer['id']]);
    throw $ex;
  }
}

// Lightweight polling endpoint used only by the admin dashboard.
if ($page === 'admin-notifications') {
  header('Content-Type: application/json; charset=utf-8');
  if (!current_user() || current_user()['role'] !== 'admin') { http_response_code(403); echo json_encode(['error'=>'Admin access required']); exit; }
  try {
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'acknowledge_order') {
      $id = (int)($_POST['order_id'] ?? 0);
      if (!isset($_SESSION['admin_checked_orders'])) $_SESSION['admin_checked_orders'] = [];
      if ($id > 0 && !in_array($id, $_SESSION['admin_checked_orders'], true)) $_SESSION['admin_checked_orders'][] = $id;
      echo json_encode(['ok'=>true]); exit;
    }
    $watermark = (int)($_SESSION['admin_order_watermark'] ?? 0);
    $checked = array_map('intval', $_SESSION['admin_checked_orders'] ?? []);
    $sql = 'SELECT o.id,o.total,o.created_at,o.notes,u.name AS customer_name FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id>?';
    $params = [$watermark];
    if ($checked) { $sql .= ' AND o.id NOT IN ('.implode(',', array_fill(0, count($checked), '?')).')'; $params = array_merge($params, $checked); }
    $sql .= ' ORDER BY o.id ASC';
    $st = $pdo->prepare($sql); $st->execute($params); $orders = $st->fetchAll();
    $itemsStmt = $pdo->prepare('SELECT product_name,quantity FROM order_items WHERE order_id=? ORDER BY id');
    foreach ($orders as &$order) { $itemsStmt->execute([$order['id']]); $order['items'] = $itemsStmt->fetchAll(); $order['total'] = (float)$order['total']; }
    unset($order);
    echo json_encode(['orders'=>$orders, 'checked'=>$checked], JSON_UNESCAPED_UNICODE); exit;
  } catch (Throwable $ex) { http_response_code(500); echo json_encode(['error'=>'Could not load order notifications']); exit; }
}
if ($page === 'customer-order-notifications') {
  header('Content-Type: application/json; charset=utf-8');
  $customer = current_user();
  if (!$customer || $customer['role'] !== 'customer') { http_response_code(403); echo json_encode(['error'=>'Customer access required']); exit; }
  try {
    $stmt = db()->prepare('SELECT id,status FROM orders WHERE user_id=? ORDER BY id');
    $stmt->execute([$customer['id']]);
    $orders = $stmt->fetchAll();
    $currentStatuses = [];
    foreach ($orders as $order) {
      $orderId = (int) $order['id'];
      $currentStatuses[$orderId] = (string) $order['status'];
    }
    echo json_encode(['orders' => $currentStatuses], JSON_UNESCAPED_UNICODE); exit;
  } catch (Throwable $ex) {
    error_log('Andys Store customer order notification request failed: ' . $ex->getMessage());
    http_response_code(500); echo json_encode(['error'=>'Could not load order updates']); exit;
  }
}
$user = current_user();
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
      case 'signup':
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass = $_POST['password'] ?? '';
        $phone = trim($_POST['phone'] ?? '');
        $schoolType = $_POST['school_type'] ?? '';
        $schoolLevel = $_POST['school_level'] ?? '';
        $course = trim($_POST['course'] ?? '');
        $year = trim($_POST['year_level'] ?? '');
        $strand = trim($_POST['strand'] ?? '');
        $section = trim($_POST['section'] ?? '');

        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$phone || !$section || !in_array($schoolType, ['university', 'lhs'], true)) {
          throw new Exception('Complete all required fields with a valid email address.');
        }

        if ($schoolType === 'university') {
          if (!$course || !in_array($year, ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'], true)) {
            throw new Exception('Enter your university course and year level.');
          }
          $strand = null;
        } else {
          $grades = $schoolLevel === 'junior' ? ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'] : ($schoolLevel === 'senior' ? ['Grade 11', 'Grade 12'] : []);
          if (!$grades || !in_array($year, $grades, true)) {
            throw new Exception('Choose your high school level, grade, and section.');
          }
          if ($schoolLevel === 'senior' && !in_array($strand, ['HUMSS', 'ABM', 'STEM'], true)) {
            throw new Exception('Choose a valid senior high school strand.');
          }
          if ($schoolLevel === 'junior') {
            $strand = null;
          }
          $course = $schoolLevel === 'junior' ? 'Junior High School' : 'Senior High School';
        }

        if (!is_strong_password($pass)) {
          throw new Exception('Password must be at least 8 characters and include an uppercase letter, a lowercase letter, and a special character.');
        }

        $pdo = db();
        $stmt = $pdo->prepare("INSERT INTO users(name,email,password_hash,role,account_status,phone,school_type,course,year_level,strand,section) VALUES(?,?,?,'customer','approved',?,?,?,?,?,?)");
        $stmt->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $phone, $schoolType, $course, $year, $strand, $section]);
        $userId = (int) $pdo->lastInsertId();
        $code = (string) random_int(100000, 999999);
        $pdo->prepare('INSERT INTO email_verification_tokens(user_id,token_hash,expires_at,attempts,requested_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE),0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),expires_at=VALUES(expires_at),attempts=0,requested_at=VALUES(requested_at)')
          ->execute([$userId, hash('sha256', $code)]);
        try {
          send_account_email($email, $name, "Verify your Andy's Store account",
            '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#333"><h2>Welcome to Andy\'s Store!</h2><p>Use this code to verify your email address:</p><p style="font-size:32px;font-weight:bold;letter-spacing:8px;background:#f7eaf0;padding:18px;text-align:center">'.e($code).'</p><p>This code expires in 10 minutes. If you did not create this account, you can ignore this email.</p></div>',
            "Welcome to Andy's Store! Your email verification code is: {$code}. It expires in 10 minutes.");
        } catch (Throwable $mailError) {
          $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id=?')->execute([$userId]);
          $pdo->prepare("DELETE FROM users WHERE id=? AND role='customer' AND email_verified_at IS NULL")->execute([$userId]);
          throw $mailError;
        }
        flash('Account created. A verification code has been sent to your email. Check your inbox and spam folder.');
        redirect('index.php?page=verify-email');

      case 'verify_customer_email':
        $email = strtolower(trim($_POST['email'] ?? ''));
        $code = trim($_POST['verification_code'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[0-9]{6}$/', $code)) {
          throw new Exception('The verification code is invalid or expired. Please create a new request or contact support.');
        }

        $pdo = db();
        $stmt = $pdo->prepare("SELECT u.id,v.token_hash,v.expires_at,v.attempts,(v.expires_at > UTC_TIMESTAMP()) AS is_valid FROM users u JOIN email_verification_tokens v ON v.user_id=u.id WHERE u.email=? AND u.role='customer' AND u.email_verified_at IS NULL LIMIT 1");
        $stmt->execute([$email]);
        $verification = $stmt->fetch();
        if (!$verification || !(int) $verification['is_valid'] || (int) $verification['attempts'] >= 5) {
          if ($verification) {
            $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id=?')->execute([$verification['id']]);
          }
          throw new Exception('The verification code is invalid or expired. Please create a new request or contact support.');
        }
        if (!hash_equals($verification['token_hash'], hash('sha256', $code))) {
          if ((int) $verification['attempts'] >= 4) {
            $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id=?')->execute([$verification['id']]);
          } else {
            $pdo->prepare('UPDATE email_verification_tokens SET attempts=attempts+1 WHERE user_id=?')->execute([$verification['id']]);
          }
          throw new Exception('The verification code is invalid or expired. Please create a new request or contact support.');
        }
        $pdo->prepare('UPDATE users SET email_verified_at=UTC_TIMESTAMP() WHERE id=? AND role=\'customer\'')
          ->execute([$verification['id']]);
        $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id=?')->execute([$verification['id']]);
        flash('Email verified successfully. You can now sign in.');
        redirect('index.php?page=login');

      case 'login':
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass = $_POST['password'] ?? '';
        $stmt = db()->prepare("SELECT id,name,email,password_hash,role,account_status,email_verified_at FROM users WHERE email=? AND role='customer' LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($pass, $user['password_hash'])) {
          throw new Exception('Email or password is incorrect.');
        }
        if (!$user['email_verified_at']) {
          throw new Exception('Verify your email address using the code sent to your email before signing in.');
        }
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => 'customer'];
        redirect('index.php');

      case 'reset_customer_password':
        $email = strtolower(trim($_POST['email'] ?? ''));
        $code = strtoupper(trim($_POST['reset_code'] ?? ''));
        $pass = $_POST['password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[A-F0-9]{16}$/', $code)) {
          throw new Exception('The reset PIN is invalid or expired. Request a new PIN by email.');
        }
        if (!is_strong_password($pass)) {
          throw new Exception('Password must be at least 8 characters and include an uppercase letter, a lowercase letter, and a special character.');
        }
        if ($pass !== $confirmPass) {
          throw new Exception('The new passwords do not match.');
        }

        $pdo = db();
        $stmt = $pdo->prepare("SELECT u.id,r.token_hash,r.attempts,(r.expires_at > UTC_TIMESTAMP()) AS is_valid FROM users u JOIN password_reset_codes r ON r.user_id=u.id WHERE u.email=? AND u.role='customer' AND u.email_verified_at IS NOT NULL LIMIT 1");
        $stmt->execute([$email]);
        $reset = $stmt->fetch();
        if (!$reset || !(int) $reset['is_valid']) {
          if ($reset) {
            $pdo->prepare('DELETE FROM password_reset_codes WHERE user_id=?')->execute([$reset['id']]);
          }
          throw new Exception('The reset PIN is invalid or expired. Request a new PIN by email.');
        }
        if (!password_verify($code, $reset['token_hash'])) {
          if ((int) $reset['attempts'] >= 4) {
            $pdo->prepare('DELETE FROM password_reset_codes WHERE user_id=?')->execute([$reset['id']]);
          } else {
            $pdo->prepare('UPDATE password_reset_codes SET attempts=attempts+1 WHERE user_id=?')->execute([$reset['id']]);
          }
          throw new Exception('The reset PIN is invalid or expired. Request a new PIN by email.');
        }

        $pdo->beginTransaction();
        try {
          $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
            ->execute([password_hash($pass, PASSWORD_DEFAULT), $reset['id']]);
          $pdo->prepare('DELETE FROM password_reset_codes WHERE user_id=?')->execute([$reset['id']]);
          $pdo->commit();
        } catch (Throwable $ex) {
          $pdo->rollBack();
          throw $ex;
        }
        flash('Password updated. You can now sign in with your new password.');
        unset($_SESSION['reset_email']);
        redirect('index.php?page=login');

      case 'request_password_reset':
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
          $pdo = db();
          $stmt = $pdo->prepare("SELECT id,name,email FROM users WHERE email=? AND role='customer' AND email_verified_at IS NOT NULL LIMIT 1");
          $stmt->execute([$email]);
          $customer = $stmt->fetch();
          if ($customer) {
            $cooldown = $pdo->prepare('SELECT requested_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 SECOND) FROM password_reset_codes WHERE user_id=?');
            $cooldown->execute([$customer['id']]);
            if (!$cooldown->fetchColumn()) {
              send_password_reset_pin($pdo, $customer);
            }
          }
        }
        flash('If a verified customer account matches that email, a reset PIN has been sent. Check your inbox and spam folder.');
        $_SESSION['reset_email'] = $email;
        redirect('index.php?page=forgot-password&pin_sent=1');

      case 'generate_customer_verification_code':
        require_admin();
        $userId = (int) ($_POST['user_id'] ?? 0);
        $stmt = db()->prepare("SELECT id,email FROM users WHERE id=? AND role='customer' AND email_verified_at IS NULL LIMIT 1");
        $stmt->execute([$userId]);
        $customer = $stmt->fetch();
        if (!$customer) {
          throw new Exception('Unverified customer account not found.');
        }
        $code = (string) random_int(100000, 999999);
        db()->prepare('INSERT INTO email_verification_tokens(user_id,token_hash,expires_at,attempts,requested_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR),0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),expires_at=VALUES(expires_at),attempts=0,requested_at=VALUES(requested_at)')
          ->execute([$customer['id'], hash('sha256', $code)]);
        send_account_email($customer['email'], $customer['email'], "Your Andy's Store verification code", '<p>Your email verification code is:</p><p style="font-size:28px;font-weight:bold;letter-spacing:6px">'.e($code).'</p><p>This code expires in 10 minutes.</p>', 'Your email verification code is: '.$code.'. It expires in 10 minutes.');
        flash('A new verification code was sent directly to the customer email.');
        redirect('index.php?page=admin-dashboard&section=customers');

      case 'update_customer_approval':
        require_admin();
        $userId = (int) ($_POST['user_id'] ?? 0);
        $accountStatus = $_POST['account_status'] ?? '';
        if (!in_array($accountStatus, ['approved', 'rejected'], true)) {
          throw new Exception('Invalid customer account decision.');
        }
        $stmt = db()->prepare("UPDATE users SET account_status=? WHERE id=? AND role='customer'");
        $stmt->execute([$accountStatus, $userId]);
        if (!$stmt->rowCount()) {
          $check = db()->prepare("SELECT id FROM users WHERE id=? AND role='customer'");
          $check->execute([$userId]);
          if (!$check->fetchColumn()) {
            throw new Exception('Customer account not found.');
          }
        }
        flash($accountStatus === 'approved' ? 'Customer account approved.' : 'Customer account rejected.');
        redirect('index.php?page=admin-dashboard&section=customers');

      case 'admin_login':
        $adminUsername = (string) getenv('ANDYS_ADMIN_USERNAME');
        $adminPassword = (string) getenv('ANDYS_ADMIN_PASSWORD');
        if ($adminUsername === '' || $adminPassword === '') {
          throw new Exception('Admin sign-in is not configured. Set ANDYS_ADMIN_USERNAME and ANDYS_ADMIN_PASSWORD.');
        }
        if (!hash_equals($adminUsername, trim($_POST['username'] ?? '')) || !hash_equals($adminPassword, (string) ($_POST['password'] ?? ''))) {
          throw new Exception('Admin username or password is incorrect.');
        }

        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => 0, 'name' => 'Store Administrator', 'email' => '', 'role' => 'admin', 'username' => 'admin'];
        redirect('index.php?page=admin-dashboard');

      case 'save_profile':
        if (!current_user() || current_user()['role'] !== 'customer') {
          throw new Exception('Please sign in as a customer.');
        }

        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $schoolType = $_POST['school_type'] ?? '';
        $schoolLevel = $_POST['school_level'] ?? '';
        $course = trim($_POST['course'] ?? '');
        $year = trim($_POST['year_level'] ?? '');
        $strand = trim($_POST['strand'] ?? '');
        $section = trim($_POST['section'] ?? '');
        $locationAddress = trim($_POST['location_address'] ?? '');

        if (!$name || !$phone || !$section || !in_array($schoolType, ['university', 'lhs'], true)) {
          throw new Exception('Please complete all required profile fields.');
        }
        if ($schoolType === 'university') {
          if (!$course || !in_array($year, ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'], true)) {
            throw new Exception('Enter your university course and year level.');
          }
          $strand = null;
        } else {
          $grades = $schoolLevel === 'junior' ? ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'] : ($schoolLevel === 'senior' ? ['Grade 11', 'Grade 12'] : []);
          if (!$grades || !in_array($year, $grades, true)) {
            throw new Exception('Choose your high school level and grade.');
          }
          if ($schoolLevel === 'senior' && !in_array($strand, ['HUMSS', 'ABM', 'STEM'], true)) {
            throw new Exception('Choose a valid senior high school strand.');
          }
          if ($schoolLevel === 'junior') {
            $strand = null;
          }
          $course = $schoolLevel === 'junior' ? 'Junior High School' : 'Senior High School';
        }

        $picture = null;
        if (!empty($_FILES['profile_picture']['name'])) {
          if ($_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK || $_FILES['profile_picture']['size'] > 3145728) {
            throw new Exception('Profile picture must be under 3 MB.');
          }

          $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['profile_picture']['tmp_name']);
          $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
          if (!$ext) {
            throw new Exception('Use a JPG, PNG, or WEBP picture.');
          }

          if (!is_dir(__DIR__ . '/uploads')) {
            mkdir(__DIR__ . '/uploads', 0755, true);
          }

          $filename = 'profile_' . (int) current_user()['id'] . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
          $destination = __DIR__ . '/uploads/' . $filename;
          if (!move_uploaded_file($_FILES['profile_picture']['tmp_name'], $destination)) {
            throw new Exception('Could not save picture.');
          }

          $picture = 'uploads/' . $filename;
        }

        $query = 'UPDATE users SET name=?,phone=?,school_type=?,course=?,year_level=?,strand=?,section=?,location_address=?' . ($picture ? ',profile_picture=?' : '') . ' WHERE id=?';
        $params = [$name, $phone, $schoolType, $course, $year, $strand, $section, $locationAddress];
        if ($picture) {
          $params[] = $picture;
        }
        $params[] = (int) current_user()['id'];

        db()->prepare($query)->execute($params);
        $_SESSION['user']['name'] = $name;
        flash('Profile updated.');
        redirect('index.php?page=profile');

      case 'save_admin_profile':
        require_admin();
        $_SESSION['user']['name'] = trim($_POST['display_name'] ?? 'Store Administrator') ?: 'Store Administrator';
        flash('Admin profile updated for this session.');
        redirect('index.php?page=admin-dashboard&section=settings');

      case 'add_product':
      case 'update_product':
        require_admin();
        $name = trim($_POST['name'] ?? '');
        $categoryId = (($_POST['category_id'] ?? '') !== '') ? (int) $_POST['category_id'] : null;
        $pdo = db();
        ensure_product_images_table($pdo);
        $categoryStmt = $pdo->prepare('SELECT slug FROM categories WHERE id=?');
        $categoryStmt->execute([$categoryId]);
        $categorySlug = (string) ($categoryStmt->fetchColumn() ?: '');
        $priceInput = trim((string) ($_POST['price'] ?? ''));
        if (!$name) {
          throw new Exception('Enter a product name.');
        }
        if ($categorySlug === 'meals') {
          $options = parse_product_options((string) ($_POST['price_options'] ?? ''), $categorySlug);
          $price = $options[0]['price'];
        } else {
          if (!is_numeric($priceInput) || (float) $priceInput <= 0) {
            throw new Exception('Enter a valid standard price.');
          }
          $price = (float) $priceInput;
          $options = parse_product_flavors((string) ($_POST['flavors'] ?? ''), $price);
        }

        $description = trim($_POST['description'] ?? '');
        $badge = trim($_POST['badge'] ?? '') ?: null;
        $isAvailable = isset($_POST['is_available']) ? 1 : 0;
        $images = save_product_images();

        $pdo->beginTransaction();
        try {
          if ($action === 'add_product') {
            $pdo->prepare('INSERT INTO products(category_id,name,description,price,image_url,badge,is_available) VALUES(?,?,?,?,NULL,?,?)')
              ->execute([$categoryId, $name, $description, $price, $badge, $isAvailable]);
            $productId = (int) $pdo->lastInsertId();
          } else {
            $productId = (int) ($_POST['id'] ?? 0);
            if ($productId < 1) {
              throw new Exception('Product not found.');
            }
            $query = 'UPDATE products SET category_id=?,name=?,description=?,price=?,badge=?,is_available=?';
            $params = [$categoryId, $name, $description, $price, $badge, $isAvailable];
            $query .= ' WHERE id=?';
            $params[] = $productId;
            $pdo->prepare($query)->execute($params);

            $removeImageIds = array_values(array_filter(array_map('intval', (array) ($_POST['remove_image_ids'] ?? [])), fn($id) => $id > 0));
            if ($removeImageIds) {
              $deleteImages = $pdo->prepare('DELETE FROM product_images WHERE product_id=? AND id IN (' . implode(',', array_fill(0, count($removeImageIds), '?')) . ')');
              $deleteImages->execute(array_merge([$productId], $removeImageIds));
            }
          }

          $existingImages = get_product_images($pdo, $productId);
          if (count($existingImages) + count($images) > 6) {
            throw new Exception('Each product can have up to 6 images. Remove an existing image before adding more.');
          }
          $sortStmt = $pdo->prepare('UPDATE product_images SET sort_order=? WHERE id=? AND product_id=?');
          foreach ($existingImages as $index => $existingImage) {
            $sortStmt->execute([$index, $existingImage['id'], $productId]);
          }
          $imageStmt = $pdo->prepare('INSERT INTO product_images(product_id,image_url,sort_order) VALUES(?,?,?)');
          foreach ($images as $index => $imageUrl) {
            $imageStmt->execute([$productId, $imageUrl, count($existingImages) + $index]);
          }
          $allImages = get_product_images($pdo, $productId);
          $pdo->prepare('UPDATE products SET image_url=? WHERE id=?')
            ->execute([$allImages[0]['image_url'] ?? null, $productId]);

          $pdo->prepare('DELETE FROM product_options WHERE product_id=?')->execute([$productId]);
          if ($options) {
            $optionStmt = $pdo->prepare('INSERT INTO product_options(product_id,option_name,price) VALUES(?,?,?)');
            foreach ($options as $option) {
              $optionStmt->execute([$productId, $option['name'], $option['price']]);
            }
          }
          $pdo->commit();
          flash($action === 'add_product' ? 'Product created.' : 'Product updated.');
        } catch (Throwable $ex) {
          $pdo->rollBack();
          foreach ($images as $imageUrl) {
            $savedPath = __DIR__ . '/' . $imageUrl;
            if (is_file($savedPath) && !unlink($savedPath)) {
              error_log('Unable to remove a product image after the product update failed.');
            }
          }
          throw $ex;
        }

        redirect('index.php?page=admin-dashboard&section=products');

      case 'set_payment_status':
        require_admin();
        $status = $_POST['payment_status'] ?? 'unpaid';
        if (!in_array($status, ['unpaid', 'paid'], true)) {
          throw new Exception('Invalid payment status.');
        }

        db()->prepare('UPDATE orders SET payment_status=? WHERE id=?')->execute([$status, (int) $_POST['order_id']]);
        flash('Cashier payment status updated.');
        redirect('index.php?page=admin-dashboard&section=orders');

      case 'delete_product':
        require_admin();
        db()->prepare('DELETE FROM products WHERE id=?')->execute([(int) $_POST['id']]);
        flash('Product deleted.');
        redirect('index.php?page=admin-dashboard&section=products');

      case 'logout':
        $_SESSION = [];
        session_destroy();
        redirect('index.php?page=login');

      case 'toggle_product':
        require_admin();
        db()->prepare('UPDATE products SET is_available=1-is_available WHERE id=?')->execute([(int) $_POST['id']]);
        redirect('index.php?page=admin-dashboard&section=products');

      case 'update_order_status':
        require_admin();
        $allowedStatuses = ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'];
        $newStatus = $_POST['status'] ?? 'pending';
        if (!in_array($newStatus, $allowedStatuses, true)) {
          throw new Exception('Invalid order status.');
        }

        db()->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$newStatus, (int) $_POST['order_id']]);
        flash('Order status updated.');
        redirect('index.php?page=admin-dashboard&section=orders');

      case 'add_to_cart':
        require_customer();
        $id = (int) ($_POST['product_id'] ?? 0);
        $qty = max(1, min(20, (int) ($_POST['quantity'] ?? 1)));
        $choice = trim($_POST['price_option'] ?? $_POST['meal_choice'] ?? '');

        $stmt = db()->prepare('SELECT p.id,p.name,p.is_available,c.slug category_slug FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.id=?');
        $stmt->execute([$id]);
        $product = $stmt->fetch();

        if (!$product || !(int) $product['is_available']) {
          throw new Exception('This item is not available.');
        }

        $options = get_product_options(db(), $id);
        if (count($options) === 1 && $choice === '') {
          $choice = $options[0]['option_name'];
        } elseif (count($options) > 1) {
          $validOption = false;
          foreach ($options as $option) {
            if ($option['option_name'] === $choice) {
              $validOption = true;
              break;
            }
          }
          if (!$validOption) {
            throw new Exception('Choose a valid flavor or price option for this item.');
          }
        } elseif (!$options) {
          $choice = '';
        } elseif ($choice !== $options[0]['option_name']) {
          throw new Exception('Choose a valid flavor or price option for this item.');
        }

        if (!isset($_SESSION['cart'])) {
          $_SESSION['cart'] = [];
        }

        $key = $id . '|' . $choice;
        $_SESSION['cart'][$key] = ['quantity' => min(99, (int) ($_SESSION['cart'][$key]['quantity'] ?? 0) + $qty)];
        flash($product['name'] . ' added to cart.');
        redirect('index.php?page=cart');

      case 'update_cart':
        require_customer();
        $key = (string) ($_POST['cart_key'] ?? '');
        $qty = (int) ($_POST['quantity'] ?? 0);

        if (isset($_SESSION['cart'][$key])) {
          $_SESSION['cart'][$key]['quantity'] = max(1, min(99, $qty));
        }

        redirect('index.php?page=cart');

      case 'remove_from_cart':
        require_customer();
        $key = (string) ($_POST['cart_key'] ?? '');
        unset($_SESSION['cart'][$key]);
        redirect('index.php?page=cart');

      case 'place_order':
        require_customer();
        $fulfillment = $_POST['fulfillment'] ?? '';
        $deliveryNotes = trim($_POST['delivery_notes'] ?? '');
        if (!in_array($fulfillment, ['pickup', 'delivery'], true)) {
          $error = 'Choose delivery or pickup.';
          break;
        }
        if ($fulfillment === 'delivery' && ($deliveryNotes === '' || strlen($deliveryNotes) > 480)) {
          $error = 'For delivery, enter the building and room number (up to 480 characters).';
          break;
        }
        $orderNotes = $fulfillment === 'delivery'
          ? 'Delivery location: ' . $deliveryNotes
          : 'Pickup at the campus canteen.';

        $cart = $_SESSION['cart'] ?? [];
        $pdo = db();
        $pdo->beginTransaction();

        try {
          $items = [];
          $total = 0;

          foreach ($cart as $key => $entry) {
            [$id, $choice] = array_pad(explode('|', (string) $key, 2), 2, '');
            $quantity = max(1, min(99, (int) ($entry['quantity'] ?? 0)));
            $stmt = $pdo->prepare('SELECT p.id,p.name,p.price,p.is_available,c.slug category_slug FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.id=?');
            $stmt->execute([(int) $id]);
            $product = $stmt->fetch();

            if (!$product || !(int) $product['is_available']) {
              throw new Exception('An item in your cart is no longer available. Remove it before placing your order.');
            }

            if ($product) {
              $unitPrice = (float) $product['price'];
              $options = get_product_options($pdo, (int) $product['id']);
              if (count($options) === 1 && $choice === '') {
                $choice = $options[0]['option_name'];
              }
              if ($options) {
                $matchingOption = null;
                foreach ($options as $option) {
                  if ($option['option_name'] === $choice) {
                    $matchingOption = $option;
                    break;
                  }
                }
                if (!$matchingOption) {
                  throw new Exception('A selected flavor or price option is missing or no longer available. Please update your cart.');
                }
                $unitPrice = (float) $matchingOption['price'];
              } elseif ($choice !== '') {
                throw new Exception('A selected flavor or price option is no longer available. Please update your cart.');
              }

              $items[] = [$product, $quantity, $choice, $unitPrice];
              $total += $unitPrice * $quantity;
            }
          }

          if (!$items) {
            throw new Exception('Your cart is empty.');
          }

          $pdo->prepare("INSERT INTO orders(user_id,total,status,payment_method,payment_status,notes) VALUES(?,?,'pending','Pay at cashier','unpaid',?)")->execute([current_user()['id'], $total, $orderNotes]);
          $orderId = $pdo->lastInsertId();

          $stmt = $pdo->prepare('INSERT INTO order_items(order_id,product_id,product_name,unit_price,quantity) VALUES(?,?,?,?,?)');
          foreach ($items as [$product, $quantity, $choice, $unitPrice]) {
            $label = $product['name'] . ($choice !== '' ? ' (' . $choice . ')' : '');
            $stmt->execute([$orderId, $product['id'], $label, $unitPrice, $quantity]);
          }

          $pdo->commit();
          unset($_SESSION['cart']);
          $_SESSION['order_just_placed'] = (int) $orderId;
          flash('Order #' . $orderId . ' placed! Status: pending.');
          redirect('index.php?page=orders');
        } catch (Throwable $ex) {
          $pdo->rollBack();
          throw $ex;
        }

      default:
        break;
    }
  }

  $categories = [];
  $products = [];
  $productOptionMap = [];
  $productImageMap = [];
  $profile = null;
  $user = current_user();
  $search = trim($_GET['q'] ?? '');
  $cat = (int) ($_GET['category'] ?? 0);

  $pdo = db();
  ensure_product_images_table($pdo);
  $categories=$pdo->query('SELECT * FROM categories WHERE is_active=1 ORDER BY id')->fetchAll();
  $user=current_user();
  if($user && $user['role']==='customer'){ $profileStmt=$pdo->prepare('SELECT * FROM users WHERE id=?'); $profileStmt->execute([$user['id']]); $profile=$profileStmt->fetch(); } else { $profile=null; }
  $sql='SELECT p.*,c.name AS category_name,c.slug AS category_slug FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.is_available=1';
  $params=[];
  if(!$cat && $search===''){ $sql.=" AND c.slug='meals'"; }
  if($cat){$sql.=' AND p.category_id=?';$params[]=$cat;}
  if($search!==''){$sql.=' AND (p.name LIKE ? OR p.description LIKE ?)';$params[]='%'.$search.'%';$params[]='%'.$search.'%';}
  $sql.=' ORDER BY p.created_at DESC';
  $st=$pdo->prepare($sql);$st->execute($params);$products=$st->fetchAll();
  $productIds = array_map(static fn(array $productRow): int => (int) $productRow['id'], $products);
  if ($productIds) {
    $productPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
    $optionsStmt = $pdo->prepare("SELECT product_id,option_name,price FROM product_options WHERE product_id IN ($productPlaceholders) ORDER BY product_id,id");
    $optionsStmt->execute($productIds);
    foreach ($optionsStmt->fetchAll() as $optionRow) {
      $productOptionMap[(int) $optionRow['product_id']][] = $optionRow;
    }

    $imagesStmt = $pdo->prepare("SELECT id,product_id,image_url FROM product_images WHERE product_id IN ($productPlaceholders) ORDER BY product_id,sort_order,id");
    $imagesStmt->execute($productIds);
    $seenProductImages = [];
    foreach ($imagesStmt->fetchAll() as $imageRow) {
      $productId = (int) $imageRow['product_id'];
      if (isset($seenProductImages[$productId][$imageRow['image_url']])) {
        continue;
      }
      $seenProductImages[$productId][$imageRow['image_url']] = true;
      $productImageMap[$productId][] = $imageRow;
    }
  }
} catch(PDOException $ex) {
  error_log('Andys Store database request failed: ' . $ex->getMessage());
  $error = 'The store is temporarily unavailable. Please try again later.';
} catch(Throwable $ex) {
  $error=$ex->getMessage();
  $user=current_user();
}
$flash=$_SESSION['flash']??''; unset($_SESSION['flash']);
function active($p,$current){return $p===$current?'active':'';}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Andy's Store — University Canteen</title>
<link rel="stylesheet" href="style.css?v=beverage-gallery">
</head>
<body>
  <header class="topbar">
    <a class="brand" href="index.php">
      <span>Andy’s Store</span>
      <small>THE UNIVERSITY CANTEEN</small>
    </a>

    <form class="search" action="index.php">
      <input name="q" placeholder="Search menu..." value="<?= e($search ?? '') ?>">
      <button aria-label="Search">⌕</button>
    </form>
    <nav class="store-nav">
      <?php if ($user && $user['role'] === 'admin'): ?>
        <a href="index.php?page=admin-dashboard">Admin</a>
        <form method="post" class="inline">
          <input type="hidden" name="action" value="logout">
          <button class="text-btn" type="submit">Log out</button>
        </form>
      <?php elseif ($user): ?>
        <a class="nav-icon cart-icon" href="index.php?page=cart" aria-label="Cart" title="Cart">
          <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H6"/>
            <circle cx="10" cy="20" r="1"/>
            <circle cx="18" cy="20" r="1"/>
          </svg>
          <span class="cart-count"><?= array_sum(array_map(fn($x) => (int)($x['quantity'] ?? 0), $_SESSION['cart'] ?? [])) ?></span>
        </a>

        <span class="nav-divider" aria-hidden="true"></span>

        <details class="notification">
          <summary aria-label="Notifications" title="Order notifications">
            <svg class="ui-icon bell-icon" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
              <path d="M10 21h4"/>
            </svg>
            <span class="bell-dot"></span>
          </summary>
          <div class="notification-pop">
            <strong>Order updates</strong>
            <?php $ns = $pdo->prepare('SELECT id,status,created_at FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 4'); ?>
            <?php $ns->execute([$user['id']]); ?>
            <?php $notifs = $ns->fetchAll(); ?>
            <?php if (!$notifs): ?>
              <p>No order notifications yet.</p>
            <?php else: ?>
              <?php foreach ($notifs as $n): ?>
                <p>
                  Order #<?= $n['id'] ?>: <?= e(ucfirst($n['status'])) ?>
                  <small><?= e($n['created_at']) ?></small>
                </p>
              <?php endforeach; ?>
            <?php endif; ?>
            <a href="index.php?page=orders">View all orders</a>
          </div>
        </details>

        <details class="account-menu">
          <summary>
            <span class="account-avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></span>
            <span class="account-label"><?= e(explode(' ', trim($user['name']))[0]) ?></span>
            <span class="account-caret">▴</span>
          </summary>

          <div class="account-pop">
            <div class="account-head">
              <span class="account-avatar large"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></span>
              <div>
                <strong><?= e($profile['name'] ?? $user['name']) ?></strong>
                <small><?= e($profile['email'] ?? $user['email']) ?></small>
              </div>
            </div>

            <div class="account-info">
              <span>CONTACT NUMBER</span>
              <p><?= e(($profile['phone'] ?? '') ?: 'Not added yet') ?></p>
              <span>LOCATION ADDRESS</span>
              <p><?= e(($profile['location_address'] ?? '') ?: 'Not added yet') ?></p>
            </div>

            <a class="account-edit" href="index.php?page=profile">Edit Profile</a>
            <a class="account-link" href="index.php?page=orders">My Orders</a>
            <form method="post">
              <input type="hidden" name="action" value="logout">
              <button class="account-logout" type="submit">Log Out</button>
            </form>
          </div>
        </details>
      <?php else: ?>
        <a class="nav-icon cart-icon" href="index.php?page=cart" aria-label="Cart" title="Cart">
          <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H6"/>
            <circle cx="10" cy="20" r="1"/>
            <circle cx="18" cy="20" r="1"/>
          </svg>
          <span class="cart-count"><?= array_sum(array_map(fn($x) => (int)($x['quantity'] ?? 0), $_SESSION['cart'] ?? [])) ?></span>
        </a>

        <span class="nav-divider" aria-hidden="true"></span>
        <a class="login-link" href="index.php?page=login">Login</a>
        <a class="pill signup-link" href="index.php?page=signup">Sign Up</a>
      <?php endif; ?>
    </nav></header>
<?php if ($flash): ?>
  <div class="notice"><?= e($flash) ?></div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="notice error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($page === 'home'): ?>
  <main class="shell">
    <section class="hero">
      <div class="hero-copy">
        <p class="eyebrow">FRESH • LOCAL • MADE FOR CAMPUS</p>
        <h1>Good food for<br>great minds.</h1>
        <p>Savor chef-inspired meals, grab-and-go bites, and daily specials at Andy’s—the heart of campus dining.</p>
        <div class="actions">
          <a class="pill" href="#menu">Order Now ↗</a>
          <a class="outline" href="#menu">Explore Menu</a>
        </div>
      </div>

     <div class="hero-art" style="
          width: 90%;
          height: 350px;
          overflow: hidden;
          border-radius: 20px;">
          <img 
              src="Andy Store.jpg"
              alt="Andy's Store University Canteen"
              style="
                  width: 100%;
                  height: 100%;
                  object-fit: cover;
                  object-position: center;
                  display: block;">
      </div>
    </section>

    <section class="category-strip">
      <?php foreach ($categories as $c): ?>
        <a class="<?= active((string) $c['id'], (string) $cat) ?>" href="index.php?category=<?= $c['id'] ?>#menu">
          <span><?= $c['icon'] ?></span>
          <?= e($c['name']) ?>
        </a>
      <?php endforeach; ?>
      <a href="index.php#menu">All items</a>
    </section>

    <section id="menu" class="menu-section">
      <div class="section-heading">
        <div>
          <p class="eyebrow">MADE FRESH DAILY</p>
          <h2><?= $cat ? 'Browse Category' : 'Today’s Favorites' ?></h2>
        </div>
        <span class="muted"><?= count($products) ?> items</span>
      </div>

      <div class="product-grid">
        <?php foreach ($products as $p): ?>
          <?php
            $productImages = array_values(array_filter(
              $productImageMap[(int) $p['id']] ?? [],
              fn($image) => is_file(__DIR__ . '/' . $image['image_url'])
            ));
          ?>
          <article class="product-card">
            <div class="product-photo">
              <div class="product-gallery-main">
                <?php if ($productImages): ?>
                  <img class="product-image" data-product-main-image src="<?= e($productImages[0]['image_url']) ?>" alt="<?= e($p['name']) ?>">
                <?php else: ?>
                  <span class="food-emoji">🍽️</span>
                <?php endif; ?>
                <?php if ($p['badge']): ?>
                  <span class="badge"><?= e($p['badge']) ?></span>
                <?php endif; ?>
              </div>
              <?php if (count($productImages) > 1): ?>
                <div class="product-image-thumbnails" aria-label="<?= e($p['name']) ?> images">
                  <?php foreach ($productImages as $imageIndex => $image): ?>
                    <button type="button" class="product-image-thumbnail<?= $imageIndex === 0 ? ' active' : '' ?>" data-image-src="<?= e($image['image_url']) ?>" data-image-alt="<?= e($p['name'] . ' image ' . ($imageIndex + 1)) ?>" aria-label="Show image <?= $imageIndex + 1 ?>" aria-pressed="<?= $imageIndex === 0 ? 'true' : 'false' ?>">
                      <img src="<?= e($image['image_url']) ?>" alt="">
                    </button>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="product-info">
              <p class="category-label"><?= e($p['category_name'] ?? 'Menu item') ?></p>
              <h3><?= e($p['name']) ?></h3>
              <p class="description"><?= e($p['description']) ?></p>

              <?php
                $priceOptions = $productOptionMap[(int) $p['id']] ?? [];
                $isBeverage = ($p['category_slug'] ?? '') !== 'meals'; // true = flavor-style item (all non-meal categories)
                $hasServingOptions = count($priceOptions) > 1 || ($isBeverage && count($priceOptions) > 0);
                $displayPrice = $priceOptions ? (float) $priceOptions[0]['price'] : (float) $p['price'];
              ?>
              <div class="product-price-row">
                <strong class="product-price" data-product-price>₱<?= number_format($displayPrice, 2) ?></strong>
                <span class="availability <?= $p['is_available'] ? 'available' : 'sold-out' ?>"><?= $p['is_available'] ? 'Available' : 'Sold Out' ?></span>
              </div>

              <?php if ($p['is_available'] && $user && $user['role'] === 'customer'): ?>
                <form method="post" action="index.php?page=home" class="add-cart-form">
                  <input type="hidden" name="action" value="add_to_cart">
                  <input type="hidden" name="product_id" value="<?= $p['id'] ?>">

                  <?php if ($hasServingOptions): ?>
                    <label>
                      <?= $isBeverage ? 'Choose a flavor:' : 'Select Option / Portion / Size:' ?>
                      <select name="price_option" required>
                        <?php if ($isBeverage && count($priceOptions) > 1): ?>
                          <option value="" selected disabled>Choose a flavor</option>
                        <?php endif; ?>
                        <?php foreach ($priceOptions as $priceOption): ?>
                          <option value="<?= e($priceOption['option_name']) ?>" data-price="<?= e(number_format((float) $priceOption['price'], 2, '.', '')) ?>"><?= e($priceOption['option_name']) ?><?= $isBeverage ? '' : ' – ₱' . number_format((float) $priceOption['price'], 2) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                  <?php endif; ?>

                  <div class="product-cart-controls">
                    <label class="quantity-label">Quantity
                      <span class="quantity-control">
                        <button type="button" class="quantity-step" data-quantity-step="-1" aria-label="Decrease quantity">−</button>
                        <input type="number" name="quantity" min="1" max="20" value="1" required aria-label="Quantity">
                        <button type="button" class="quantity-step" data-quantity-step="1" aria-label="Increase quantity">+</button>
                      </span>
                    </label>
                    <button class="pill small" type="submit">Add to Cart</button>
                  </div>
                </form>
              <?php elseif (!$p['is_available']): ?>
                <button class="pill small sold-out-button" type="button" disabled>Sold Out</button>
              <?php else: ?>
                <a class="pill small" href="index.php?page=login">Log in to order</a>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if (!$products): ?>
        <p class="empty">No menu items found. Try another search or category.</p>
      <?php endif; ?>
    </section>

    <footer>Made with care at Andy’s Store <span>Good food for great minds.</span></footer>
  </main>

<?php elseif (in_array($page, ['login', 'signup', 'admin', 'forgot-password', 'verify-email'])): ?>
  <main class="auth-wrap">
    <section class="auth-card">
      <a class="back" href="index.php">← Back to store</a>
      <div class="monogram">A</div>

      <p class="eyebrow"><?= $page === 'admin' ? 'STAFF ACCESS' : 'WELCOME TO ANDY’S' ?></p>
      <h1><?= $page === 'signup' ? 'Create your account' : ($page === 'admin' ? 'Admin sign in' : ($page === 'forgot-password' ? 'Reset your password' : ($page === 'verify-email' ? 'Verify your email' : 'Welcome back'))) ?></h1>
      <p class="muted"><?= $page === 'admin' ? 'Authorized administrators only.' : ($page === 'forgot-password' ? 'Request a one-time PIN by email, then choose a new password.' : 'Your campus favorites are just a sign in away.') ?></p>

      <?php if ($page === 'forgot-password'): ?>
        <form method="post" class="auth-form">
          <input type="hidden" name="action" value="request_password_reset">
          <label>Email address<input name="email" type="email" required value="<?= e($_SESSION['reset_email'] ?? '') ?>" placeholder="you@university.edu"></label>
          <button class="pill full">Email me a reset PIN</button>
        </form>

        <?php if (isset($_GET['pin_sent'])): ?>
          <form method="post" class="auth-form">
            <input type="hidden" name="action" value="reset_customer_password">
            <label>Email address<input name="email" type="email" required value="<?= e($_SESSION['reset_email'] ?? '') ?>" placeholder="you@university.edu"></label>
            <label>Email reset PIN<input name="reset_code" required minlength="16" maxlength="16" pattern="[A-Fa-f0-9]{16}" autocomplete="one-time-code" placeholder="16-character PIN"></label>
            <label>New password<input name="password" type="password" minlength="8" required autocomplete="new-password" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[\x21-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E]).{8,}" title="Use at least 8 characters, including an uppercase letter, a lowercase letter, and a special character." placeholder="Uppercase, lowercase & symbol (8+)"></label>
            <label>Confirm new password<input name="confirm_password" type="password" minlength="8" required autocomplete="new-password"></label>
            <button class="pill full">Update Password</button>
          </form>
        <?php endif; ?>
      <?php elseif ($page === 'verify-email'): ?>
        <p class="muted">Enter the six-digit verification code sent to your registered email address. The code expires in 10 minutes.</p>
        <form method="post" class="auth-form">
          <input type="hidden" name="action" value="verify_customer_email">
          <label>Email address<input name="email" type="email" required placeholder="you@university.edu"></label>
          <label>Verification code<input name="verification_code" inputmode="numeric" autocomplete="one-time-code" required minlength="6" maxlength="6" pattern="[0-9]{6}" placeholder="6-digit code"></label>
          <button class="pill full">Verify email address</button>
        </form>
      <?php else: ?>
      <form method="post" class="auth-form">
        <input type="hidden" name="action" value="<?= $page === 'signup' ? 'signup' : ($page === 'admin' ? 'admin_login' : 'login') ?>">

        <?php if ($page === 'signup'): ?>
          <label>Full name<input name="name" required placeholder="Your name"></label>
          <label>Phone number<input name="phone" type="tel" required placeholder="09xx xxx xxxx"></label>
          <label>
            School
            <select name="school_type" id="school-type" required>
              <option value="university">University</option>
              <option value="lhs">LHS (Laboratory Highschool)</option>
            </select>
          </label>
          <div class="auth-field-group" data-school-group="university">
            <label>Course / Program<input name="course" required placeholder="e.g. BS Computer Science"></label>
            <label>Year level
              <select name="year_level" required data-grade-select="university">
                <option value="">Select year</option>
                <option>1st Year</option><option>2nd Year</option><option>3rd Year</option><option>4th Year</option><option>5th Year</option>
              </select>
            </label>
          </div>
          <div class="auth-field-group" data-school-group="lhs" hidden>
            <label>High school level
              <select name="school_level" id="school-level" required>
                <option value="">Select level</option>
                <option value="junior">Junior High School</option>
                <option value="senior">Senior High School</option>
              </select>
            </label>
            <label>Grade
              <select name="year_level" id="lhs-grade" required>
                <option value="">Select grade</option>
                <option data-level="junior">Grade 7</option><option data-level="junior">Grade 8</option><option data-level="junior">Grade 9</option><option data-level="junior">Grade 10</option>
                <option data-level="senior">Grade 11</option><option data-level="senior">Grade 12</option>
              </select>
            </label>
            <label data-school-subgroup="senior" hidden>Strand
              <select name="strand" id="lhs-strand" required>
                <option value="">Select strand</option><option>HUMSS</option><option>ABM</option><option>STEM</option>
              </select>
            </label>
          </div>
          <label>Section<input name="section" required placeholder="e.g. 3A"></label>
        <?php endif; ?>

        <?php if ($page !== 'admin'): ?>
          <label>Email address<input name="email" type="email" required placeholder="you@university.edu"></label>
        <?php else: ?>
          <label>Username<input name="username" required autocomplete="username"></label>
        <?php endif; ?>

          <label>Password<input name="password" type="password" minlength="8" required placeholder="<?= $page === 'signup' ? 'Uppercase, lowercase & symbol (8+)' : 'Password' ?>" <?= $page === 'signup' ? 'autocomplete="new-password" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[\x21-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E]).{8,}" title="Use at least 8 characters, including an uppercase letter, a lowercase letter, and a special character."' : 'autocomplete="current-password"' ?>></label>
          <button class="pill full"><?= $page === 'signup' ? 'Create Account' : 'Sign In' ?></button>
      </form>
      <?php endif; ?>

      <?php if ($page === 'login'): ?>
        <p class="switch"><a href="index.php?page=forgot-password">Forgot your password?</a></p>
        <p class="switch">New to Andy’s? <a href="index.php?page=signup">Create an account</a></p>
      <?php elseif ($page === 'forgot-password'): ?>
        <p class="switch">Remembered your password? <a href="index.php?page=login">Log in</a></p>
      <?php elseif ($page === 'signup'): ?>
        <p class="switch">Already registered? <a href="index.php?page=login">Log in</a></p>
        <p class="switch">Need to verify your email? <a href="index.php?page=verify-email">Enter your email code</a></p>
      <?php elseif ($page === 'verify-email'): ?>
        <p class="switch">Already verified? <a href="index.php?page=login">Log in</a></p>
      <?php endif; ?>

    </section>
  </main>

<?php elseif ($page === 'admin-dashboard'): ?>
  <?php require_admin(); ?>

  <?php if (!isset($_SESSION['admin_order_watermark'])): ?>
    <?php $_SESSION['admin_order_watermark'] = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM orders')->fetchColumn(); ?>
    <?php $_SESSION['admin_checked_orders'] = []; ?>
  <?php endif; ?>

  <main class="dashboard">
    <aside class="sidebar">
      <a class="brand" href="index.php">
        <span>Andy’s Store</span>
        <small>ADMIN CONSOLE</small>
      </a>

      <div class="sidebar-label">WORKSPACE</div>
      <a class="side-link <?= $adminSection === 'dashboard' ? 'active' : '' ?>" href="index.php?page=admin-dashboard&section=dashboard"><span class="side-icon">▦</span> Dashboard</a>
      <a class="side-link <?= $adminSection === 'orders' ? 'active' : '' ?>" href="index.php?page=admin-dashboard&section=orders"><span class="side-icon">▤</span> Orders <span class="side-badge"><?= $pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn() ?></span></a>
      <a class="side-link <?= $adminSection === 'products' ? 'active' : '' ?>" href="index.php?page=admin-dashboard&section=products"><span class="side-icon">♧</span> Menu & Products</a>
      <a class="side-link <?= $adminSection === 'customers' ? 'active' : '' ?>" href="index.php?page=admin-dashboard&section=customers"><span class="side-icon">♙</span> Customers <span class="side-badge"><?= $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer' AND account_status='pending'")->fetchColumn() ?></span></a>
      <a class="side-link <?= $adminSection === 'reports' ? 'active' : '' ?>" href="index.php?page=admin-dashboard&section=reports"><span class="side-icon">↗</span> Sales & Reports</a>

      <div class="sidebar-spacer"></div>

      <a class="side-link <?= $adminSection === 'settings' ? 'active' : '' ?>" href="index.php?page=admin-dashboard&section=settings"><span class="side-icon">⚙</span> Settings</a>
      <a class="side-link" href="index.php"><span class="side-icon">↗</span> View Store</a>

      <form method="post">
        <input type="hidden" name="action" value="logout">
        <button class="side-link logout-link" type="submit"><span class="side-icon">⇥</span> Log out</button>
      </form>
    </aside>

    <section class="admin-content" id="dashboard-home">
      <?php if ($adminSection === 'dashboard'): ?>
      <div class="admin-topline">
        <div>
          <p class="eyebrow">STORE MANAGEMENT</p>
          <h1>Good day, <?= e(explode(' ', trim($user['name'] ?? 'Administrator'))[0]) ?>!</h1>
          <p class="muted">Here’s what’s happening at Andy’s Store today.</p>
        </div>
        <a class="pill admin-view-store" href="index.php">↗ View storefront</a>
      </div>

      <div class="admin-panel new-order-alert" id="new-order-alert" aria-live="polite">
        <div class="new-order-alert-head">
          <div>
            <h2>🔔 New order alerts</h2>
            <p class="muted">Keep this dashboard open. New orders will sound until you acknowledge each one.</p>
          </div>
          <button type="button" class="pill small" id="enable-order-sound">Enable order sound</button>
        </div>
        <p id="sound-state" class="muted">Sound is off until you enable it.</p>
        <div id="new-order-list">
          <p class="muted">Waiting for new orders…</p>
        </div>
      </div>

      <div class="stats">
        <a href="index.php?page=admin-dashboard&section=products" class="stat-card">
          <span><i>♧</i> Menu items</span>
          <strong><?= $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() ?></strong>
          <small>Manage your food catalog <b>→</b></small>
        </a>

        <a href="index.php?page=admin-dashboard&section=orders" class="stat-card">
          <span><i>▤</i> Orders today</span>
          <strong><?= $pdo->query('SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()')->fetchColumn() ?></strong>
          <small>View customer orders <b>→</b></small>
        </a>

        <a href="index.php?page=admin-dashboard&section=customers" class="stat-card">
          <span><i>♙</i> Customers</span>
          <strong><?= $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn() ?></strong>
          <small>Customer directory <b>→</b></small>
        </a>

        <a href="index.php?page=admin-dashboard&section=reports" class="stat-card">
          <span><i>₱</i> Paid sales today</span>
          <strong>₱<?= number_format((float) $pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid' AND DATE(created_at)=CURDATE()")->fetchColumn(), 2) ?></strong>
          <small>See sales overview <b>→</b></small>
        </a>
      </div>

      <div class="quick-actions">
        <strong>Quick actions</strong>
        <a href="index.php?page=admin-dashboard&section=orders">Review orders <span>→</span></a>
        <a href="index.php?page=admin-dashboard&section=products">Add or edit a product <span>→</span></a>
        <a href="index.php?page=admin-dashboard&section=reports">Check today’s sales <span>→</span></a>
      </div>
      <?php elseif ($adminSection === 'orders'): ?>
        <div class="admin-topline"><div><p class="eyebrow">WORKSPACE</p><h1>Orders</h1><p class="muted">Review customer orders and update their status or payment.</p></div><a class="pill admin-view-store" href="index.php">↗ View storefront</a></div>
      <?php elseif ($adminSection === 'products'): ?>
        <div class="admin-topline"><div><p class="eyebrow">WORKSPACE</p><h1>Menu & Products</h1><p class="muted">Create, edit, publish, and manage your store catalog.</p></div><a class="pill admin-view-store" href="index.php">↗ View storefront</a></div>
      <?php elseif ($adminSection === 'customers'): ?>
        <div class="admin-topline"><div><p class="eyebrow">WORKSPACE</p><h1>Customers</h1><p class="muted">View registered customer contact and course details.</p></div><a class="pill admin-view-store" href="index.php">↗ View storefront</a></div>
      <?php elseif ($adminSection === 'reports'): ?>
        <div class="admin-topline"><div><p class="eyebrow">WORKSPACE</p><h1>Sales & Reports</h1><p class="muted">Review paid sales, outstanding payments, and order totals.</p></div><a class="pill admin-view-store" href="index.php">↗ View storefront</a></div>
      <?php else: ?>
        <div class="admin-topline"><div><p class="eyebrow">WORKSPACE</p><h1>Settings</h1><p class="muted">Update the admin display name shown in the console.</p></div><a class="pill admin-view-store" href="index.php">↗ View storefront</a></div>
      <?php endif; ?>

      <?php if ($adminSection === 'settings'): ?>
      <div class="admin-panel" id="admin-profile">
        <h2>Admin profile</h2>
        <p>Signed in as <strong><?= e($user['name'] ?? 'Store Administrator') ?></strong> (username: admin)</p>
        <form method="post" class="product-form">
          <input type="hidden" name="action" value="save_admin_profile">
          <label class="wide">
            Display name
            <input name="display_name" value="<?= e($user['name'] ?? 'Store Administrator') ?>">
          </label>
          <button class="pill">Save profile</button>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($adminSection === 'products'): ?>
      <div class="admin-panel">
        <h2>Create menu item</h2>
        <form method="post" enctype="multipart/form-data" class="product-form">
          <input type="hidden" name="action" value="add_product">
          <label>Item name<input name="name" required></label>
          <label>
            Category
            <select name="category_id" class="product-category">
              <option value="" data-slug="">Uncategorized</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" data-slug="<?= e($c['slug']) ?>"><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="standard-price-label">Standard price<input class="standard-price" name="price" type="number" min="0.01" step=".01" required><small>Used for beverages and standard-price items such as snacks and merienda.</small></label>
          <label class="wide price-options-field" hidden>Meal prices<small>Enter both meal choices with their prices, one per line as Name | Price. Example: Meal with Rice | 40 and Meal Only | 35.</small><textarea name="price_options" rows="4" placeholder="Meal with Rice | 40&#10;Meal Only | 35"></textarea></label>
          <label class="wide flavors-field" hidden>Flavors (optional)<small>One flavor per line, e.g. Orange, Lemon, Grape. All flavors use the standard price above. Leave empty if the item has no flavors.</small><textarea name="flavors" rows="4" placeholder="Apple&#10;Mango&#10;Strawberry"></textarea></label>
          <label>Badge<input name="badge" placeholder="POPULAR, VEGAN..."></label>
          <label class="wide">Description<input name="description"></label>
          <label class="wide">Product images<input name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple><small>Choose up to 6 JPG, PNG, or WEBP images, up to 5 MB each.</small></label>
          <label class="availability-toggle"><input class="availability-checkbox" type="checkbox" name="is_available" value="1" checked> Available to customers</label>
          <button class="pill">Add item</button>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($adminSection === 'orders'): ?>
      <div class="admin-panel" id="orders">
        <div class="panel-title-row">
          <div>
            <p class="eyebrow">ORDER WORKSPACE</p>
            <h2>Customer orders & checkout</h2>
            <p class="muted">Search an order or filter by status, review delivery notes, then update its status or payment.</p>
          </div>
          <span class="panel-count"><?= $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() ?> total</span>
        </div>

        <div class="order-tools">
          <label class="order-search">
            <span>⌕</span>
            <input id="order-search" type="search" placeholder="Search order number, customer, or food...">
          </label>
          <select id="order-status-filter" aria-label="Filter orders by status">
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="confirmed">Confirmed</option>
            <option value="preparing">Preparing</option>
            <option value="ready">Ready</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Order</th>
                <th>Customer</th>
                <th>Items</th>
                <th>Total</th>
                <th>Placed</th>
                <th>Fulfillment / notes</th>
                <th>Status</th>
                <th>Update</th>
              </tr>
            </thead>
            <tbody>
              <?php $adminOrders = $pdo->query('SELECT o.*,u.name customer_name,u.email customer_email FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.created_at DESC')->fetchAll(); ?>
              <?php foreach ($adminOrders as $ao): ?>
                <?php $itemStmt = $pdo->prepare('SELECT product_name,quantity FROM order_items WHERE order_id=?'); ?>
                <?php $itemStmt->execute([$ao['id']]); ?>
                <?php $orderItems = $itemStmt->fetchAll(); ?>
                <tr class="admin-order-row" data-status="<?= e(strtolower($ao['status'])) ?>">
                  <td><strong>#<?= $ao['id'] ?></strong></td>
                  <td><?= e($ao['customer_name']) ?><br><small><?= e($ao['customer_email']) ?></small></td>
                  <td>
                    <?php foreach ($orderItems as $oi): ?>
                      <?= e($oi['product_name']) ?> × <?= (int) $oi['quantity'] ?><br>
                    <?php endforeach; ?>
                  </td>
                  <td>₱<?= number_format($ao['total'], 2) ?></td>
                  <td><?= e($ao['created_at']) ?></td>
                  <td><?= e($ao['notes'] ?: 'Pickup at the campus canteen.') ?></td>
                  <td><span class="status"><?= e(ucfirst($ao['status'])) ?></span></td>
                  <td>
                    <div>
                      <strong><?= e(ucfirst($ao['payment_status'] ?? 'unpaid')) ?></strong>
                      <small>Pay at cashier</small>

                      <form method="post" class="status-form">
                        <input type="hidden" name="action" value="set_payment_status">
                        <input type="hidden" name="order_id" value="<?= $ao['id'] ?>">
                        <select name="payment_status">
                          <option value="unpaid" <?= ($ao['payment_status'] ?? 'unpaid') === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                          <option value="paid" <?= ($ao['payment_status'] ?? 'unpaid') === 'paid' ? 'selected' : '' ?>>Paid</option>
                        </select>
                        <button class="outline small">Save payment</button>
                      </form>

                      <form method="post" class="status-form">
                        <input type="hidden" name="action" value="update_order_status">
                        <input type="hidden" name="order_id" value="<?= $ao['id'] ?>">
                        <select name="status" aria-label="Order status">
                          <option value="pending" <?= $ao['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                          <option value="confirmed" <?= $ao['status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                          <option value="preparing" <?= $ao['status'] === 'preparing' ? 'selected' : '' ?>>Preparing</option>
                          <option value="ready" <?= $ao['status'] === 'ready' ? 'selected' : '' ?>>Ready</option>
                          <option value="completed" <?= $ao['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                          <option value="cancelled" <?= $ao['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                        <button class="outline small">Save</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (!$adminOrders): ?>
                <tr><td colspan="8">No customer orders yet. Orders will show here after customers place them.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($adminSection === 'products'): ?>
      <div class="admin-panel" id="products">
        <h2>Create, edit & delete products</h2>
        <p class="muted">Add up to 6 product images, list beverage flavors at one standard price, and set meal-with-rice and meal-only prices.</p>
        <label class="product-list-filter">
          Filter by category
          <select id="admin-product-category-filter">
            <option value="">All categories</option>
            <option value="uncategorized">Uncategorized</option>
            <?php foreach ($categories as $category): ?>
              <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Product</th>
                <th>Category</th>
                <th>Price</th>
                <th>Available</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pdo->query('SELECT p.*,c.name category_name,c.slug category_slug FROM products p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.id DESC') as $p): ?>
                <tr data-product-category="<?= $p['category_id'] === null ? 'uncategorized' : (int) $p['category_id'] ?>">
                  <td><?= e($p['name']) ?></td>
                  <td><?= e($p['category_name'] ?? '—') ?></td>
                  <td>
                    <?php $adminPriceOptions = get_product_options($pdo, (int) $p['id']); ?>
                    <?php if ($p['category_slug'] !== 'meals' && $adminPriceOptions): ?>
                      Standard: ₱<?= number_format($p['price'], 2) ?><br>
                      Flavors: <?= e(implode(', ', array_column($adminPriceOptions, 'option_name'))) ?>
                    <?php elseif ($adminPriceOptions): ?>
                      <?php foreach ($adminPriceOptions as $adminPriceOption): ?>
                        <?= e($adminPriceOption['option_name']) ?>: ₱<?= number_format((float) $adminPriceOption['price'], 2) ?><br>
                      <?php endforeach; ?>
                    <?php else: ?>
                      Standard: ₱<?= number_format($p['price'], 2) ?>
                    <?php endif; ?>
                  </td>
                  <td><?= $p['is_available'] ? 'Yes' : 'No' ?></td>
                  <td>
                    <details>
                      <summary>Edit</summary>
                      <form method="post" enctype="multipart/form-data" class="edit-product">
                        <input type="hidden" name="action" value="update_product">
                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                        <?php $adminProductImages = get_product_images($pdo, (int) $p['id']); ?>
                        <?php if ($adminProductImages): ?>
                          <div class="product-admin-gallery wide">
                            <?php foreach ($adminProductImages as $adminImage): ?>
                              <label class="product-admin-image-option">
                                <?php if (is_file(__DIR__ . '/' . $adminImage['image_url'])): ?>
                                  <img class="product-admin-image" src="<?= e($adminImage['image_url']) ?>" alt="<?= e($p['name']) ?>">
                                <?php endif; ?>
                                <span><input type="checkbox" name="remove_image_ids[]" value="<?= (int) $adminImage['id'] ?>"> Remove</span>
                              </label>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                        <label>Name<input name="name" required value="<?= e($p['name']) ?>"></label>
                        <label>
                          Category
                          <select name="category_id" class="product-category">
                            <option value="" data-slug="">Uncategorized</option>
                            <?php foreach ($categories as $c): ?>
                              <option value="<?= $c['id'] ?>" data-slug="<?= e($c['slug']) ?>" <?= $p['category_id'] == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                        <label class="standard-price-label">Standard price<input class="standard-price" name="price" type="number" min="0.01" step=".01" value="<?= e($p['price']) ?>" required><small>Used for beverages and standard-price items such as snacks and merienda.</small></label>
                        <?php $optionLines = array_map(fn($option) => $option['option_name'] . ' | ' . number_format((float) $option['price'], 2, '.', ''), $adminPriceOptions); ?>
                        <?php $flavorLines = $p['category_slug'] !== 'meals' ? array_column($adminPriceOptions, 'option_name') : []; ?>
                        <label class="wide price-options-field" hidden>Meal prices<small>Enter both meal choices with their prices, one per line as Name | Price. Example: Meal with Rice | 40 and Meal Only | 35.</small><textarea name="price_options" rows="4"><?= e(implode("\n", $p['category_slug'] === 'meals' ? $optionLines : [])) ?></textarea></label>
                        <label class="wide flavors-field" hidden>Flavors (optional)<small>One flavor per line, e.g. Orange, Lemon, Grape. All flavors use the standard price above. Leave empty if the item has no flavors.</small><textarea name="flavors" rows="4"><?= e(implode("\n", $flavorLines)) ?></textarea></label>
                        <label>Description<input name="description" value="<?= e($p['description']) ?>"></label>
                        <label>Badge<input name="badge" value="<?= e($p['badge']) ?>"></label>
                        <label class="wide">Add images<input name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple><small>Optional; up to 6 images total, up to 5 MB each. Remove existing images above to make room.</small></label>
                        <label class="availability-toggle"><input class="availability-checkbox" type="checkbox" name="is_available" value="1" <?= $p['is_available'] ? 'checked' : '' ?>> Available to customers</label>
                        <button class="pill small">Save changes</button>
                      </form>
                    </details>

                    <form method="post" onsubmit="return confirm('Delete this product?')">
                      <input type="hidden" name="action" value="delete_product">
                      <input type="hidden" name="id" value="<?= $p['id'] ?>">
                      <button class="outline small danger">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($adminSection === 'customers'): ?>
      <div class="admin-panel" id="customers">
        <div class="panel-title-row">
          <div>
            <p class="eyebrow">COMMUNITY</p>
            <h2>Customer directory</h2>
            <p class="muted">View customer accounts and email verification status. Verification codes are sent directly to customers by email; administrator approval is not required for sign-in.</p>
          </div>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Customer</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Course / Program</th>
                <th>Joined</th>
                <th>Account status</th>
                <th>Email verification</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php $customerRows = $pdo->query("SELECT id,name,email,phone,school_type,course,year_level,strand,section,created_at,account_status,email_verified_at FROM users WHERE role='customer' ORDER BY (account_status='pending') DESC,email_verified_at IS NULL DESC,created_at DESC")->fetchAll(); ?>
              <?php foreach ($customerRows as $cr): ?>
                <tr>
                  <td><strong><?= e($cr['name']) ?></strong></td>
                  <td><?= e($cr['email']) ?></td>
                  <td><?= e($cr['phone'] ?? '—') ?></td>
                  <td><?= e(($cr['school_type'] ?? 'university') === 'lhs' ? 'LHS · ' . ($cr['course'] ?? '') . ' · ' . ($cr['year_level'] ?? '') . (!empty($cr['strand']) ? ' · ' . $cr['strand'] : '') . ' · ' . ($cr['section'] ?? '') : ($cr['course'] ?? '—') . ' · ' . ($cr['year_level'] ?? '') . ' · ' . ($cr['section'] ?? '')) ?></td>
                  <td><?= e($cr['created_at'] ?? '—') ?></td>
                  <td>
                    <span class="status"><?= e(ucfirst($cr['account_status'])) ?></span>
                  </td>
                  <td>
                    <?= $cr['email_verified_at'] ? 'Verified' : 'Not verified' ?>
                  </td>
                  <td>
                    <?php if (!$cr['email_verified_at']): ?>
                      <form method="post" class="status-form">
                        <input type="hidden" name="action" value="generate_customer_verification_code">
                        <input type="hidden" name="user_id" value="<?= (int) $cr['id'] ?>">
                        <button class="outline small">Generate code</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($cr['account_status'] === 'pending'): ?>
                      <form method="post" class="status-form">
                        <input type="hidden" name="action" value="update_customer_approval">
                        <input type="hidden" name="user_id" value="<?= (int) $cr['id'] ?>">
                        <input type="hidden" name="account_status" value="approved">
                        <button class="pill small">Approve</button>
                      </form>
                      <form method="post" class="status-form" onsubmit="return confirm('Reject this customer account?')">
                        <input type="hidden" name="action" value="update_customer_approval">
                        <input type="hidden" name="user_id" value="<?= (int) $cr['id'] ?>">
                        <input type="hidden" name="account_status" value="rejected">
                        <button class="outline small danger">Reject</button>
                      </form>
                    <?php elseif ($cr['account_status'] === 'rejected'): ?>
                      <form method="post" class="status-form">
                        <input type="hidden" name="action" value="update_customer_approval">
                        <input type="hidden" name="user_id" value="<?= (int) $cr['id'] ?>">
                        <input type="hidden" name="account_status" value="approved">
                        <button class="outline small">Approve</button>
                      </form>
                    <?php else: ?>
                      <form method="post" class="status-form" onsubmit="return confirm('Revoke this customer’s account access?')">
                        <input type="hidden" name="action" value="update_customer_approval">
                        <input type="hidden" name="user_id" value="<?= (int) $cr['id'] ?>">
                        <input type="hidden" name="account_status" value="rejected">
                        <button class="outline small danger">Revoke</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (!$customerRows): ?>
                <tr><td colspan="8">No customer accounts yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($adminSection === 'reports'): ?>
      <div class="admin-panel" id="sales-report">
        <p class="eyebrow">BUSINESS OVERVIEW</p>
        <h2>Sales & reports</h2>
        <div class="report-grid">
          <div>
            <span>Paid sales today</span>
            <strong>₱<?= number_format((float) $pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid' AND DATE(created_at)=CURDATE()")->fetchColumn(), 2) ?></strong>
          </div>
          <div>
            <span>Paid sales all time</span>
            <strong>₱<?= number_format((float) $pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid'")->fetchColumn(), 2) ?></strong>
          </div>
          <div>
            <span>Awaiting payment</span>
            <strong><?= $pdo->query("SELECT COUNT(*) FROM orders WHERE payment_status='unpaid'")->fetchColumn() ?></strong>
          </div>
          <div>
            <span>Pending orders</span>
            <strong><?= $pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn() ?></strong>
          </div>
        </div>
        <p class="muted">Sales are calculated from orders marked as paid. Update payment status in the Orders section after cashier confirmation.</p>
      </div>
      <?php endif; ?>
    </section>
  </main>
<script>
(() => {
  const search = document.getElementById('order-search');
  const filter = document.getElementById('order-status-filter');
  const rows = Array.from(document.querySelectorAll('.admin-order-row'));
  function filterOrders() {
    const q = (search?.value || '').trim().toLowerCase();
    const status = filter?.value || '';
    rows.forEach(row => { row.hidden = (status && row.dataset.status !== status) || (q && !row.textContent.toLowerCase().includes(q)); });
  }
  search?.addEventListener('input', filterOrders);
  filter?.addEventListener('change', filterOrders);
})();
(() => {
  const panel = document.getElementById('new-order-alert');
  if (!panel) return;
  const list = document.getElementById('new-order-list');
  const enable = document.getElementById('enable-order-sound');
  const state = document.getElementById('sound-state');
  let audioCtx = null, soundEnabled = false, pending = new Map(), lastChime = 0, busy = false;
  function ding() {
    if (!soundEnabled || !audioCtx) return;
    const now = audioCtx.currentTime;
    // A short two-tone register-style ding, synthesized locally (no audio file needed).
    [[1320,0],[1760,0.13]].forEach(([freq, delay]) => {
      const osc = audioCtx.createOscillator(), gain = audioCtx.createGain();
      osc.type = 'sine'; osc.frequency.value = freq;
      gain.gain.setValueAtTime(0.0001, now + delay);
      gain.gain.exponentialRampToValueAtTime(0.22, now + delay + 0.012);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + delay + 0.42);
      osc.connect(gain); gain.connect(audioCtx.destination);
      osc.start(now + delay); osc.stop(now + delay + 0.44);
    });
  }
  enable.addEventListener('click', async () => {
    try {
      const Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) throw new Error('Web Audio is not supported by this browser.');
      if (!audioCtx) audioCtx = new Ctx();
      await audioCtx.resume(); soundEnabled = true;
      state.textContent = 'Order sound enabled. A ding repeats every 3 seconds while an order awaits your attention.';
      enable.textContent = 'Sound enabled ✓'; ding();
    } catch (e) { state.textContent = 'Could not enable sound. Check your browser audio settings.'; }
  });
  function safeText(tag, cls, text) { const el = document.createElement(tag); if (cls) el.className = cls; el.textContent = text; return el; }
  async function acknowledge(id) {
    const data = new URLSearchParams({action:'acknowledge_order', order_id:String(id)});
    await fetch('index.php?page=admin-notifications', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:data, credentials:'same-origin'});
    pending.delete(Number(id)); render();
  }
  function render() {
    list.replaceChildren();
    if (!pending.size) { list.append(safeText('p','muted','No unacknowledged new orders.')); return; }
    for (const o of pending.values()) {
      const card = document.createElement('div'); card.className = 'new-order-card';
      const head = document.createElement('div'); head.className = 'new-order-card-head';
      head.append(safeText('strong','',`Order #${o.id}`), safeText('strong','new-order-total',`₱${Number(o.total).toFixed(2)}`)); card.append(head);
      card.append(safeText('p','',`Customer: ${o.customer_name}`));
      card.append(safeText('p','order-fulfillment-note',o.notes || 'Pickup at the campus canteen.'));
      const foods = document.createElement('ul');
      (o.items || []).forEach(item => foods.append(safeText('li','',`${item.product_name} × ${item.quantity}`)));
      card.append(foods, safeText('small','muted',`Placed: ${o.created_at}`));
      const btn = safeText('button','pill small','I checked this order'); btn.type = 'button'; btn.addEventListener('click', () => acknowledge(o.id)); card.append(btn);
      list.append(card);
    }
  }
  async function poll() {
    if (busy) return; busy = true;
    try {
      const response = await fetch('index.php?page=admin-notifications', {cache:'no-store', credentials:'same-origin'});
      if (!response.ok) throw new Error('poll failed');
      const data = await response.json();
      (data.orders || []).forEach(o => { if (!pending.has(Number(o.id))) pending.set(Number(o.id), o); });
      render();
      if (pending.size && soundEnabled && Date.now() - lastChime >= 3000) { ding(); lastChime = Date.now(); }
    } catch (e) { state.textContent = soundEnabled ? 'Sound enabled; reconnecting to order updates…' : 'Waiting for new orders…'; }
    finally { busy = false; }
  }
  poll(); setInterval(poll, 2500);
})();
</script>
<?php elseif($page==='cart'): ?>
<main class="shell page-pad cart-page">
  <div class="cart-page-heading">
    <div><p class="eyebrow">YOUR ORDER</p><h1>Shopping Cart</h1></div>
    <a class="outline" href="index.php#menu">Continue shopping</a>
  </div>
  <?php if (!$user || $user['role'] !== 'customer'): ?>
    <p>Please <a href="index.php?page=login">log in</a> as a customer to use your cart.</p>
  <?php else:
    $cartRows = [];
    $cartTotal = 0;
    $cartHasUnavailableOption = false;
    foreach ($_SESSION['cart'] ?? [] as $key => $entry) {
      [$pid, $choice] = array_pad(explode('|', (string) $key, 2), 2, '');
      $st = $pdo->prepare('SELECT id,name,price,is_available FROM products WHERE id=?');
      $st->execute([(int) $pid]);
      if ($prod = $st->fetch()) {
        $options = get_product_options($pdo, (int) $prod['id']);
        $optionFound = !$options && $choice === '';
        if (count($options) === 1 && $choice === '') {
          $choice = $options[0]['option_name'];
        }
        foreach ($options as $option) {
          if ($option['option_name'] === $choice) {
            $prod['price'] = $option['price'];
            $optionFound = true;
            break;
          }
        }
        if (!$optionFound || !(int) $prod['is_available']) {
          $prod['price'] = null;
          $cartHasUnavailableOption = true;
        }
        $qty = max(1, (int) ($entry['quantity'] ?? 1));
        $subtotal = $prod['price'] === null ? 0 : $prod['price'] * $qty;
        $cartRows[] = [$key, $prod, $choice, $qty, $subtotal];
        $cartTotal += $subtotal;
      } else {
        $cartRows[] = [$key, ['name' => 'Unavailable product', 'price' => null], $choice, max(1, (int) ($entry['quantity'] ?? 1)), 0];
        $cartHasUnavailableOption = true;
      }
    }
  ?>
    <div class="admin-panel">
      <?php if (!$cartRows): ?>
        <p>Your cart is empty. <a href="index.php">Browse today's meals</a>.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Food name</th><th>Flavor / Meal choice / Size</th><th>Price</th><th>Quantity</th><th>Subtotal</th><th>Update</th></tr></thead>
            <tbody>
              <?php foreach ($cartRows as [$key, $prod, $choice, $qty, $subtotal]): ?>
                <tr>
                  <td><strong><?= e($prod['name']) ?></strong></td>
                  <td><?= e($choice ?: '—') ?></td>
                  <td><?= $prod['price'] === null ? 'Option unavailable' : '₱' . number_format($prod['price'], 2) ?></td>
                  <td>
                    <form method="post" class="inline" id="update-cart-<?= e($key) ?>">
                      <input type="hidden" name="action" value="update_cart">
                      <input type="hidden" name="cart_key" value="<?= e($key) ?>">
                      <input type="number" name="quantity" min="1" max="99" value="<?= $qty ?>" style="width:65px;padding:7px;border:1px solid #d8c5c3;border-radius:8px">
                    </form>
                  </td>
                  <td><?= $prod['price'] === null ? '—' : '₱' . number_format($subtotal, 2) ?></td>
                  <td class="cart-item-actions">
                    <button class="outline small" form="update-cart-<?= e($key) ?>">Update</button>
                    <form method="post">
                      <input type="hidden" name="action" value="remove_from_cart">
                      <input type="hidden" name="cart_key" value="<?= e($key) ?>">
                      <button class="outline small remove-cart-item" type="submit">Remove</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="cart-total">
          <h2>Total: ₱<?= number_format($cartTotal, 2) ?></h2>
          <?php if ($cartHasUnavailableOption): ?>
            <p class="notice error">An item or selected flavor/meal choice is no longer available. Remove it from your cart before checking out.</p>
          <?php else: ?>
          <form method="post" class="checkout-form" id="checkout-form">
            <input type="hidden" name="action" value="place_order">
            <label>
              Order fulfillment
              <select name="fulfillment" id="fulfillment" required>
                <option value="">Choose delivery or pickup</option>
                <option value="pickup">Pick up at the campus canteen</option>
                <option value="delivery">Deliver to my building/room</option>
              </select>
            </label>
            <label id="delivery-notes-label" hidden>
              Delivery location <span>(required for delivery)</span>
              <textarea name="delivery_notes" id="delivery-notes" maxlength="480" placeholder="Building name and room number"></textarea>
              <small>Include the building and room where your order should be delivered.</small>
            </label>
            <p class="muted">Pay at the campus cashier.</p>
            <button class="pill" type="submit">Place Order · Pay at Cashier</button>
          </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>
<script>
(() => {
  const fulfillment = document.getElementById('fulfillment');
  const locationLabel = document.getElementById('delivery-notes-label');
  const locationField = document.getElementById('delivery-notes');
  if (!fulfillment || !locationLabel || !locationField) return;
  function updateDeliveryFields() {
    const needsLocation = fulfillment.value === 'delivery';
    locationLabel.hidden = !needsLocation;
    locationField.required = needsLocation;
  }
  fulfillment.addEventListener('change', updateDeliveryFields);
  updateDeliveryFields();
})();
</script>
<?php elseif($page==='profile'): ?>
<main class="shell page-pad">
  <p class="eyebrow">YOUR ACCOUNT</p><h1>My Profile</h1>
  <?php if (!$user || $user['role'] !== 'customer'): ?>
    <p>Please <a href="index.php?page=login">log in</a> as a customer to view your profile.</p>
  <?php else: ?>
    <?php $profileSchoolType = $profile['school_type'] ?? 'university'; ?>
    <section class="admin-panel profile-card">
      <div class="profile-avatar">
        <?php if (!empty($profile['profile_picture']) && is_file(__DIR__ . '/' . $profile['profile_picture'])): ?>
          <img src="<?= e($profile['profile_picture']) ?>" alt="Profile picture">
        <?php else: ?>
          <?= e(strtoupper(substr($profile['name'], 0, 1))) ?>
        <?php endif; ?>
      </div>
      <form method="post" enctype="multipart/form-data" class="profile-form">
        <input type="hidden" name="action" value="save_profile">
        <label>Profile picture (JPG, PNG, WEBP; max 3 MB)<input type="file" name="profile_picture" accept="image/jpeg,image/png,image/webp"></label>
        <label>Full name<input name="name" required value="<?= e($profile['name']) ?>"></label>
        <label>Email<input value="<?= e($profile['email']) ?>" disabled></label>
        <label>Phone number<input name="phone" required value="<?= e($profile['phone']) ?>"></label>
        <label>School
          <select name="school_type" id="school-type" required>
            <option value="university" <?= $profileSchoolType === 'university' ? 'selected' : '' ?>>University</option>
            <option value="lhs" <?= $profileSchoolType === 'lhs' ? 'selected' : '' ?>>LHS (Laboratory Highschool)</option>
          </select>
        </label>
        <div class="auth-field-group" data-school-group="university">
          <label>Course / Program<input name="course" value="<?= e($profile['course']) ?>" required></label>
          <label>Year level
            <select name="year_level" required>
              <option value="">Select year</option>
              <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'] as $yearOption): ?>
                <option <?= $profile['year_level'] === $yearOption ? 'selected' : '' ?>><?= e($yearOption) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="auth-field-group" data-school-group="lhs" hidden>
          <label>High school level
            <select name="school_level" id="school-level" required>
              <option value="">Select level</option>
              <option value="junior" <?= $profile['course'] === 'Junior High School' ? 'selected' : '' ?>>Junior High School</option>
              <option value="senior" <?= $profile['course'] === 'Senior High School' ? 'selected' : '' ?>>Senior High School</option>
            </select>
          </label>
          <label>Grade
            <select name="year_level" id="lhs-grade" required>
              <option value="">Select grade</option>
              <?php foreach (['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'] as $grade): ?>
                <option data-level="junior" <?= $profile['year_level'] === $grade ? 'selected' : '' ?>><?= $grade ?></option>
              <?php endforeach; ?>
              <?php foreach (['Grade 11', 'Grade 12'] as $grade): ?>
                <option data-level="senior" <?= $profile['year_level'] === $grade ? 'selected' : '' ?>><?= $grade ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label data-school-subgroup="senior" hidden>Strand
            <select name="strand" id="lhs-strand" required>
              <option value="">Select strand</option>
              <?php foreach (['HUMSS', 'ABM', 'STEM'] as $strandOption): ?>
                <option <?= ($profile['strand'] ?? '') === $strandOption ? 'selected' : '' ?>><?= $strandOption ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <label>Section<input name="section" required value="<?= e($profile['section']) ?>"></label>
        <label class="wide">Location address<input name="location_address" value="<?= e($profile['location_address'] ?? '') ?>" placeholder="Enter your address"></label>
        <button class="pill">Save profile</button>
      </form>
    </section>
  <?php endif; ?>
</main>
<?php elseif($page==='orders'): ?>
<main class="shell page-pad">
  <p class="eyebrow">YOUR CAMPUS ORDERS</p>
  <h1>My Orders</h1>
  <?php if (!$user): ?>
    <p>Please <a href="index.php?page=login">log in</a> to view orders.</p>
  <?php else:
    $justPlacedOrderId = (int) ($_SESSION['order_just_placed'] ?? 0);
    unset($_SESSION['order_just_placed']);
    $st = $pdo->prepare('SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC');
    $st->execute([$user['id']]);
    $orders = $st->fetchAll();
  ?>
    <div class="admin-panel">
      <div class="new-order-alert-head">
        <div>
          <strong>Order updates</strong>
          <p class="muted" id="customer-order-sound-state">Enable sound to hear order confirmations and status changes while this page is open.</p>
        </div>
        <button type="button" class="pill small" id="enable-customer-order-sound">Enable order sound</button>
      </div>
      <?php if (!$orders): ?>
        No orders yet. <a href="index.php">Browse the menu</a>.
      <?php else: ?>
        <?php foreach ($orders as $o): ?>
          <div class="order-row">
            <div>
              <strong>Order #<?= $o['id'] ?></strong>
              <p class="muted"><?= e($o['created_at']) ?></p>
              <?php
                $it = $pdo->prepare('SELECT product_name,quantity FROM order_items WHERE order_id=?');
                $it->execute([$o['id']]);
                foreach ($it->fetchAll() as $item):
              ?>
                <p class="order-item-name"><?= e($item['quantity']) ?> × <?= e($item['product_name']) ?></p>
              <?php endforeach; ?>
              <p class="order-item-name"><?= e($o['notes'] ?: 'Pickup at the campus canteen.') ?></p>
            </div>
            <span class="status customer-order-status" data-order-id="<?= (int) $o['id'] ?>"><?= e(ucfirst($o['status'])) ?></span>
            <strong>₱<?= number_format($o['total'], 2) ?></strong>
            <small>Pay at cashier · <?= e(ucfirst($o['payment_status'] ?? 'unpaid')) ?></small>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>
<?php if ($user && $user['role'] === 'customer'): ?>
<script>
(() => {
  const enable = document.getElementById('enable-customer-order-sound');
  const state = document.getElementById('customer-order-sound-state');
  const justPlacedOrderId = <?= (int) ($justPlacedOrderId ?? 0) ?>;
  const knownStatuses = new Map(
    Array.from(document.querySelectorAll('.customer-order-status'), element => [
      Number(element.dataset.orderId),
      element.textContent.trim().toLowerCase()
    ])
  );
  let audioContext = null, soundEnabled = false, busy = false;

  function ding() {
    if (!soundEnabled || !audioContext) return;
    const now = audioContext.currentTime;
    [[1320, 0], [1760, 0.13]].forEach(([frequency, delay]) => {
      const oscillator = audioContext.createOscillator();
      const gain = audioContext.createGain();
      oscillator.type = 'sine';
      oscillator.frequency.value = frequency;
      gain.gain.setValueAtTime(0.0001, now + delay);
      gain.gain.exponentialRampToValueAtTime(0.18, now + delay + 0.012);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + delay + 0.38);
      oscillator.connect(gain);
      gain.connect(audioContext.destination);
      oscillator.start(now + delay);
      oscillator.stop(now + delay + 0.4);
    });
  }

  enable.addEventListener('click', async () => {
    try {
      const AudioContextClass = window.AudioContext || window.webkitAudioContext;
      if (!AudioContextClass) throw new Error('Web Audio is not supported.');
      if (!audioContext) audioContext = new AudioContextClass();
      await audioContext.resume();
      soundEnabled = true;
      enable.textContent = 'Sound enabled ✓';
      state.textContent = justPlacedOrderId
        ? `Order #${justPlacedOrderId} was placed. Sound is enabled for status updates.`
        : 'Sound is enabled for status updates while this page is open.';
      ding();
      poll();
    } catch (error) {
      state.textContent = 'Could not enable sound. Check your browser audio settings.';
    }
  });

  async function poll() {
    if (busy) return;
    busy = true;
    try {
      const response = await fetch('index.php?page=customer-order-notifications', {
        cache: 'no-store',
        credentials: 'same-origin'
      });
      if (!response.ok) throw new Error('Could not load order updates.');
      const data = await response.json();
      Object.entries(data.orders || {}).forEach(([id, status]) => {
        const orderId = Number(id);
        const previousStatus = knownStatuses.get(orderId);
        const currentStatus = String(status).toLowerCase();
        const statusElement = document.querySelector(`.customer-order-status[data-order-id="${orderId}"]`);
        if (statusElement) statusElement.textContent = currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1);
        if (previousStatus && previousStatus !== currentStatus && soundEnabled) {
          state.textContent = `Order #${orderId} status changed to ${currentStatus}.`;
          ding();
        }
        knownStatuses.set(orderId, currentStatus);
      });
    } catch (error) {
      state.textContent = soundEnabled ? 'Sound enabled; reconnecting to order updates…' : state.textContent;
    } finally {
      busy = false;
    }
  }

  poll();
  window.setInterval(poll, 5000);
})();
</script>
<?php endif; ?>
<?php else: ?><main class="shell page-pad"><h1>Page not found</h1><a href="index.php">Return to store</a></main><?php endif; ?>
<script>
document.addEventListener('click', function(event) {
  document.querySelectorAll('.account-menu[open]').forEach(function(menu) {
    if (!menu.contains(event.target)) menu.removeAttribute('open');
  });
});
document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape') document.querySelectorAll('.account-menu[open]').forEach(function(menu) { menu.removeAttribute('open'); });
});
const schoolTypeField = document.getElementById('school-type');
if (schoolTypeField) {
  const schoolLevelField = document.getElementById('school-level');
  const gradeField = document.getElementById('lhs-grade');
  function updateSchoolFields() {
    document.querySelectorAll('[data-school-group]').forEach(function(group) {
      const visible = group.dataset.schoolGroup === schoolTypeField.value;
      group.hidden = !visible;
      group.querySelectorAll('input,select,textarea').forEach(function(field) { field.disabled = !visible; });
    });
    const level = schoolLevelField ? schoolLevelField.value : '';
    document.querySelectorAll('[data-school-subgroup="senior"]').forEach(function(group) {
      const visible = schoolTypeField.value === 'lhs' && level === 'senior';
      group.hidden = !visible;
      group.querySelectorAll('input,select,textarea').forEach(function(field) { field.disabled = !visible; });
    });
    if (gradeField) {
      Array.from(gradeField.options).forEach(function(option) {
        if (!option.dataset.level) return;
        option.disabled = option.dataset.level !== level;
        option.hidden = option.disabled;
      });
      if (gradeField.selectedOptions.length && gradeField.selectedOptions[0].dataset.level && gradeField.selectedOptions[0].dataset.level !== level) {
        gradeField.value = '';
      }
    }
  }
  schoolTypeField.addEventListener('change', updateSchoolFields);
  if (schoolLevelField) schoolLevelField.addEventListener('change', updateSchoolFields);
  updateSchoolFields();
}
function updateProductPricingFields(categorySelect) {
  const form = categorySelect.closest('form');
  const slug = categorySelect.selectedOptions[0] ? categorySelect.selectedOptions[0].dataset.slug : '';
  const hasOptions = slug === 'meals';
  const hasFlavors = slug !== 'meals' && slug !== '';
  const standardPrice = form.querySelector('.standard-price');
  const standardPriceLabel = form.querySelector('.standard-price-label');
  const optionsField = form.querySelector('.price-options-field');
  const flavorsField = form.querySelector('.flavors-field');
  standardPrice.required = !hasOptions;
  standardPriceLabel.hidden = hasOptions;
  optionsField.hidden = !hasOptions;
  optionsField.querySelector('textarea').required = hasOptions;
  flavorsField.hidden = !hasFlavors;
}
document.querySelectorAll('.product-category').forEach(function(categorySelect) {
  categorySelect.addEventListener('change', function() { updateProductPricingFields(categorySelect); });
  updateProductPricingFields(categorySelect);
});
document.querySelectorAll('.product-card').forEach(function(card) {
  const mainImage = card.querySelector('[data-product-main-image]');
  card.querySelectorAll('.product-image-thumbnail').forEach(function(thumbnail) {
    thumbnail.addEventListener('click', function() {
      if (!mainImage) return;
      mainImage.src = thumbnail.dataset.imageSrc;
      mainImage.alt = thumbnail.dataset.imageAlt;
      card.querySelectorAll('.product-image-thumbnail').forEach(function(other) {
        const selected = other === thumbnail;
        other.classList.toggle('active', selected);
        other.setAttribute('aria-pressed', selected ? 'true' : 'false');
      });
    });
  });
  const optionSelect = card.querySelector('select[name="price_option"]');
  const price = card.querySelector('[data-product-price]');
  function updateOptionPrice() {
    if (!optionSelect || !price || !optionSelect.selectedOptions.length) return;
    price.textContent = '₱' + Number(optionSelect.selectedOptions[0].dataset.price).toFixed(2);
  }
  if (optionSelect) {
    optionSelect.addEventListener('change', updateOptionPrice);
    updateOptionPrice();
  }
});
document.querySelectorAll('.quantity-control').forEach(function(control) {
  const input = control.querySelector('input[name="quantity"]');
  if (!input) return;
  control.querySelectorAll('[data-quantity-step]').forEach(function(button) {
    button.addEventListener('click', function() {
      const step = Number(button.dataset.quantityStep);
      const current = Number.parseInt(input.value, 10) || 1;
      input.value = String(Math.max(Number(input.min) || 1, Math.min(Number(input.max) || 20, current + step)));
    });
  });
  input.addEventListener('change', function() {
    const current = Number.parseInt(input.value, 10) || 1;
    input.value = String(Math.max(Number(input.min) || 1, Math.min(Number(input.max) || 20, current)));
  });
});
const productCategoryFilter = document.getElementById('admin-product-category-filter');
if (productCategoryFilter) {
  const productRows = Array.from(document.querySelectorAll('#products tbody tr[data-product-category]'));
  productCategoryFilter.addEventListener('change', function() {
    const categoryId = productCategoryFilter.value;
    productRows.forEach(function(row) {
      row.hidden = categoryId !== '' && row.dataset.productCategory !== categoryId;
    });
  });
}
</script>
</body>
</html>