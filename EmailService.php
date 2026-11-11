<?php
declare(strict_types=1);

// Use require_once to prevent multiple inclusions of the autoloader
require_once __DIR__ . '/vendor/autoload.php'; 

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Wrap the class in a check to prevent "Cannot declare class" fatal errors
if (!class_exists('EmailService')) {
    class EmailService
    {
        private string $host;
        private string $username;
        private string $password;
        private int $port;
        private string $fromEmail;
        private string $fromName;

        public function __construct(array $config)
        {
            $this->host       = $config['host'] ?? 'smtp.gmail.com';
            $this->username   = $config['username'] ?? '';
            $this->password   = $config['password'] ?? '';
            $this->port       = $config['port'] ?? 587;
            $this->fromEmail  = $config['from_email'] ?? '';
            $this->fromName   = $config['from_name'] ?? "Andy's Store";
        }

        public function sendToUser(string $toEmail, string $toName, string $subject, string $htmlBody, string $altBody = ''): array
        {
            if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => "Invalid email address: $toEmail"];
            }

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = $this->host;
                $mail->SMTPAuth   = true;
                $mail->Username   = $this->username;
                $mail->Password   = $this->password;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
                $mail->Port       = $this->port;
                $mail->CharSet    = 'UTF-8';
                
                $mail->setFrom($this->fromEmail, $this->fromName);
                $mail->addAddress($toEmail, trim($toName));
                
                $mail->isHTML(true);
                $mail->Subject = $subject;
                
                // Securely inject the user's name into the template
                $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
                $mail->Body    = str_replace('{{name}}', $safeName, $htmlBody);
                $mail->AltBody = $altBody ?: strip_tags($htmlBody);
                
                $mail->send();
                return ['success' => true, 'message' => "Email successfully sent to $toEmail"];
            } catch (Exception $e) {
                error_log("PHPMailer Error: {$mail->ErrorInfo}");
                return ['success' => false, 'message' => "Email failed to send. Please try again later."];
            }
        }
    }
}