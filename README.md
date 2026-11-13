# Andy's Store — University Canteen System

A responsive PHP + MySQL starter system styled to match the supplied reference: dusty rose, soft blush, pastel pink, warm off-white, Playfair-style headings, rounded cards and pill buttons.

## Features
- Customer registration and login (passwords stored with PHP `password_hash`)
- Registration supports university students and LHS (Laboratory Highschool) students; high school accounts capture grade, section, and the HUMSS, ABM, or STEM strand for senior high school
- Email verification for new customer accounts; customers must verify their address before sign-in
- Verification codes can be resent with a cooldown, and duplicate email addresses are rejected
- Forgot-password recovery with one-time PINs sent to the customer's verified email; PINs expire after 15 minutes and allow up to five attempts
- Separate admin sign-in route
- Browse menu items, search, and filter by category
- Consistent product cards show images, category, price, availability, serving options, and quantity controls; option prices update when a serving size is selected
- Shopping cart merges repeated additions of the same product and option, keeps different meal options separate, supports quantity updates and item removal, and carries the correct prices through checkout
- Meals support editable price options (meal with rice/meal only); beverages, snacks, and merienda use a standard price
- Customer orders and order history
- Admins and customers can save their in-browser order sound preference across visits and disable it any time; browsers may still require a click to resume audio after reload, and notifications only run while the relevant page is open
- Checkout requires customers to choose pickup or delivery; delivery orders require a building and room location saved with the order and shown to admins
- Checkout supports campus pickup or delivery; delivery requires a building and room location, saved with the order for customers and admins
- Admin dashboard with menu item counts, add-item form, publish/hide menu items, and an order-management table showing customer, ordered items, total, date, and status
- Admin console has separate Dashboard, Orders, Menu & Products, Customers, Sales & Reports, and Settings pages
- Admins can upload or replace product images when creating or editing products; supported formats are JPG, PNG, and WEBP, up to 5 MB
- Admins can configure meal prices in the product editor, one option per line in `Name | Price` format. Meals require both `Meal with rice` and `Meal only` (example: ₱40 with rice and ₱35 meal only). Beverages use a single standard price and do not have size options.
- Existing meal entries are migrated with their current catalog price as the initial price for each option. Existing beverage size options are removed automatically; each beverage uses its product's standard price.
- Additional products identified from the bundled catalog photos are added as unavailable until an admin enters a price and enables them
- Admin can update order status (pending, confirmed, preparing, ready, completed, cancelled); customers can see the current status in My Orders
- MySQL schema for users, categories, products, orders, and order items
- Responsive layout for desktop and mobile

### Email delivery (Brevo or Resend API)
1. For a free option that does not require owning a domain, create a Brevo account, add a sender email address you can access, and enter the verification code Brevo sends to it. Create a transactional email API key. Brevo recommends authenticating a domain for deliverability, but its sender setup permits email verification for an unauthenticated domain.
2. In Render or Railway, set `MAIL_PROVIDER=brevo`, `BREVO_API_KEY`, and `BREVO_FROM_EMAIL`. For local development, copy `mail.local.php.example` to `mail.local.php` and fill in those values. Never commit `mail.local.php`.
3. Resend remains available if you own a domain: set `MAIL_PROVIDER=resend`, `RESEND_API_KEY`, and `RESEND_FROM_EMAIL` to an address at your verified domain. If `MAIL_PROVIDER` is omitted, the app defaults to Resend for compatibility.
4. Ensure PHP cURL is enabled; the included Dockerfile installs it. For local testing only, open `check_mail_config.php?to=you@example.com` to send a test email. This test endpoint is excluded from the Docker deployment.

### Optional production demo customers
To add the 25 clearly labeled, synthetic, unverified customer records to a database for dashboard testing, open `seed_demo_customers.sql` in MySQL Workbench, select the existing store schema, and execute it once. Do not rerun `andys_store.sql`. The demo addresses use the reserved `.invalid` domain, so they cannot receive mail or sign in. The seed script is excluded from the Docker image. Avoid adding demo rows to a live customer directory unless you specifically need them there.

New customers receive a six-digit verification code by email. Codes expire after 10 minutes and allow up to five attempts. The application emails password-reset PINs to verified customer accounts; reset PINs expire after 15 minutes and allow up to five attempts. No email credential is stored in the repository.

After email verification, customers can sign in without administrator approval. Existing approved customer accounts are marked as email-verified when an older database is upgraded, preserving their access.

## Requirements
- PHP 8.0+ with PDO MySQL enabled
- MySQL 8+ or MariaDB
- XAMPP, WAMP, MAMP, or a PHP/MySQL hosting environment

## Deploy to Railway
1. Push the application files and the Railway deployment files (`Dockerfile` and `.dockerignore`) to the GitHub repository connected to Railway. Make sure `mail.local.php`, `db.local.php`, and `.env` are not committed.
2. In Railway, create a project from the GitHub repository and add a MySQL service.
3. In the web service's **Variables**, add the database variables using Railway's MySQL service references (replace `MySQL` with the actual database service name):

   ```text
   DB_HOST=${{MySQL.MYSQLHOST}}
   DB_PORT=${{MySQL.MYSQLPORT}}
   DB_NAME=${{MySQL.MYSQLDATABASE}}
   DB_USER=${{MySQL.MYSQLUSER}}
   DB_PASS=${{MySQL.MYSQLPASSWORD}}
   ANDYS_ADMIN_USERNAME=<choose-a-private-admin-username>
   ANDYS_ADMIN_PASSWORD=<choose-a-unique-strong-password>
   ```

   Add `MAIL_PROVIDER=brevo`, `BREVO_API_KEY`, and `BREVO_FROM_EMAIL` for free account-verification and password-reset email delivery. Verify the sender address in Brevo first.
4. Import `andys_store.sql` into the Railway MySQL database using the database's connection details and a MySQL client. Do this before opening the app; the application performs some schema upgrades when it connects.
5. In Railway, create a public domain for the web service. Railway supplies the `PORT` value used by the included Dockerfile.
6. Open the public domain and test storefront, sign-in, checkout, and (if configured) email delivery.

The admin login uses `ANDYS_ADMIN_USERNAME` and `ANDYS_ADMIN_PASSWORD`; there is no longer a built-in default admin password. Keep these secrets only in Railway Variables. The database and uploaded files must be backed up separately. Railway's application filesystem is ephemeral, so images uploaded from the admin panel or profile pages may be lost on redeploy; use persistent/object storage before relying on those uploads.

## Setup with XAMPP
1. Copy the `andys_store_system` folder into `C:\\xampp\\htdocs\\`.
2. Start **Apache** and **MySQL** in XAMPP.
3. Open phpMyAdmin at `http://localhost/phpmyadmin`.
4. Import `andys_store.sql`. This creates the `andys_store` database, tables, five categories, the original catalog, and the additional photo-based products. Product photos from your ZIP are included in the `products/` folder and their relative paths are stored in the products table.
5. Configure the database with environment variables or copy `db.local.php.example` to `db.local.php` and set your local database values.
6. Configure the email provider settings above if you want to send verification and password-reset emails.
7. Visit the application URL.

## Configure admin sign-in
Set `ANDYS_ADMIN_USERNAME` and `ANDYS_ADMIN_PASSWORD` in the environment before opening `index.php?page=admin`. The application has no built-in admin credentials.

## Notes before deployment
- Prices from your uploaded product list are shown in Philippine pesos (₱). Product image paths come from your uploaded `products.zip` archive.
- Uploaded product image files are stored in `products/`; the `image_url` column stores the relative path used to display each image. Ensure the web server can write to that folder.
- This is a starter project, not a production-hardened deployment. Before going live, add CSRF protection, login rate limiting, stronger admin provisioning, order status management, validation/authorization tests, HTTPS, and secure cookie settings.
- The database stores orders and item snapshots. Payment processing is not included; orders are saved with `pending` status.


## Updated features
- Customer registration includes phone, course, year level, and section; profile picture upload is available on My Profile.
- Main storefront defaults to available Meals; categories and search let customers browse other items.
- Orders are recorded as unpaid, with instructions to pay at the campus cashier and pick up food. This project does not collect online payments.
- Customer notification dropdown shows recent order statuses.
- Admin login credentials are configured with `ANDYS_ADMIN_USERNAME` and `ANDYS_ADMIN_PASSWORD`.
- Admin dashboard includes transaction/customer counts, paid revenue, admin display profile, and product create/edit/delete controls.
- If you are upgrading an existing database, back it up and run the new schema additions from `andys_store.sql`; a fresh database import is recommended for this starter version.


## Admin new-order sound notifications
- Sign in as admin and open the Admin dashboard.
- Click **Enable order sound** once (browsers require a user interaction before audio can play).
- Keep the dashboard tab open. It checks for new orders every 2.5 seconds.
- A cash-register-style two-tone ding repeats every 3 seconds while any newly placed order is awaiting acknowledgement.
- The alert displays order number, customer name, food names and quantities, and total. Click **I checked this order** to stop that order's sound alert.
- Only the admin dashboard runs this notification feature. It uses the browser's Web Audio API and does not require an extra sound file.

## Customer account menu update
The storefront header now uses a compact account dropdown for signed-in customers. It shows the customer's name, email, contact number, location address, Edit Profile, My Orders, and Log Out. Customers can add/update their location address from My Profile. Existing databases are upgraded automatically by adding the `location_address` column when the app connects; fresh installations include it in `database.sql`. Signed-out visitors see a cart icon plus Login and Sign Up links.

## Admin dashboard navigation update
The admin dashboard now includes a sidebar for Dashboard, Orders, Menu & Products, Customers, Sales & Reports, Settings, View Store, and Log out. Dashboard cards link to common tasks and show today's orders and paid sales. The Orders table supports live text search and status filtering. A customer directory and sales summary have been added. The layout adapts to smaller screens.
#   A n d y - s - S t o r e  
 