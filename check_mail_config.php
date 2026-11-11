<?php
// Sends a test email using the real app config. Delete or protect this file before going live.
require __DIR__ . '/config.php';

$to = trim((string)($_GET['to'] ?? ''));
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    exit('Usage: check_mail_config.php?to=you@example.com');
}
$result = send_pin_email($to, 'Test', 'TEST1234');
echo $result['success']
    ? '<p style="color:green">Sent: ' . htmlspecialchars($result['message']) . '</p>'
    : '<p style="color:red">Failed: ' . htmlspecialchars($result['message']) . '</p>';
