# Andy's Store — Email Verification and Password Reset

Customer registration automatically emails a six-digit verification code to the address entered during signup. Codes expire after 10 minutes and allow up to five attempts. Customers can sign in after verifying their email; administrator approval is not required.

Forgot-password requests send a 16-character reset PIN directly to the customer's verified email address. Reset PINs expire after 15 minutes and failed attempts are limited.

## Setup
1. Start Apache and MySQL in XAMPP.
2. Import `andys_store.sql` in phpMyAdmin. Configure the database with environment variables or copy `db.local.php.example` to `db.local.php` and set your local values.
3. Install dependencies from the project folder with `composer install` if needed.
4. Configure Gmail SMTP by copying `mail.local.php.example` to `mail.local.php` and setting `ANDYS_GMAIL_ADDRESS` and `ANDYS_GMAIL_APP_PASSWORD`. Use a Google App Password, not your regular Gmail password. Keep `mail.local.php` private and out of Git.
5. Open `check_mail_config.php?to=you@example.com` to test delivery.

## Test
1. Register using an email inbox you can access.
2. Find the six-digit code in your inbox or spam folder and enter it on Verify Email.
3. Sign in after verification; no administrator approval is needed.
4. Use Forgot your password, request a PIN, and follow the emailed instructions to set a new password.

If a message cannot be sent, check the Gmail address, App Password, SMTP connection, and PHP error log. Password reset responses intentionally do not reveal whether an email address has an account.
