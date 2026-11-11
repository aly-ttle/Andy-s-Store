# Andy's Store — University Canteen System

A responsive PHP + MySQL starter system styled to match the supplied reference: dusty rose, soft blush, pastel pink, warm off-white, Playfair-style headings, rounded cards and pill buttons.

## Features
- Customer registration and login (passwords stored with PHP `password_hash`)
- Registration supports university students and LHS (Laboratory Highschool) students; high school accounts capture grade, section, and the HUMSS, ABM, or STEM strand for senior high school
- New customer accounts require administrator approval before sign-in; admins can approve or reject accounts from the Customers page
- Email verification for new customer accounts; customers must verify their address and receive administrator approval before sign-in
- Forgot-password recovery with one-time PINs sent to the customer's verified email; PINs expire after 15 minutes and allow up to five attempts
- Separate admin sign-in route
- Browse menu items, search, and filter by category
- Consistent product cards show images, category, price, availability, serving options, and quantity controls; option prices update when a serving size is selected
- Shopping cart merges repeated additions of the same product and option, keeps different meal options separate, supports quantity updates and item removal, and carries the correct prices through checkout
- Meals support editable price options (meal with rice/meal only); beverages, snacks, and merienda use a standard price
- Customer orders and order history
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

### Email delivery (PHPMailer + Gmail SMTP)
1. Turn on 2-Step Verification for the sending Gmail account and create an App Password in Google Account security settings.
2. Copy `mail.local.php.example` to `mail.local.php` and fill in `ANDYS_GMAIL_ADDRESS` and `ANDYS_GMAIL_APP_PASSWORD` (or set them as real environment variables and restart Apache). Never commit `mail.local.php`.
3. Install dependencies from the application directory with `composer install`.
4. Open `check_mail_config.php?to=you@example.com` to send a test email.

Administrators generate six-digit verification codes in **Admin → Customers** and share them with customers manually. Codes expire after 24 hours and allow up to five attempts. The application emails password-reset PINs to verified customer accounts; reset PINs expire after 15 minutes and allow up to five attempts. No email credential is stored in the repository.

### New customer account approval
1. A new customer registers and contacts the store administrator for a verification code.
2. In **Admin → Customers**, generate a code and share it with the customer, who enters it on the **Verify your email** page.
3. Review the account and select **Approve** or **Reject**. Both code verification and administrator approval are required before customer sign-in.
4. Existing approved customer accounts are marked as email-verified when an older database is upgraded, preserving their access. New accounts must verify their address.

## Requirements
- PHP 8.0+ with PDO MySQL enabled
- MySQL 8+ or MariaDB
- XAMPP, WAMP, MAMP, or a PHP/MySQL hosting environment

## Setup with XAMPP
1. Copy the `andys_store_system` folder into `C:\\xampp\\htdocs\\`.
2. Start **Apache** and **MySQL** in XAMPP.
3. Open phpMyAdmin at `http://localhost/phpmyadmin`.
4. Import `database.sql`. This creates the `andys_store` database, tables, five categories, the original catalog, and the additional photo-based products. Product photos from your ZIP are included in the `products/` folder and their relative paths are stored in the products table.
5. Check `config.php` and update the database host/name/user/password for your environment.
6. Configure the Gmail email settings above for password-reset emails.
7. Visit the application URL.

## Create the first admin account
For safety, public registration always creates a **customer** account. Create the first admin using a one-time local script or promote an account directly in MySQL.

Recommended simple method:
1. Register a normal account from the Sign Up page.
2. In phpMyAdmin, select the `andys_store` database and run this SQL, replacing the email with your registered admin email:

```sql
UPDATE users SET role = 'admin' WHERE email = 'your-admin-email@example.com';
```

3. Open `http://localhost/andys_store_system/index.php?page=admin` and sign in with that account. The admin page only accepts accounts whose database role is `admin`.

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
- Admin login: username `admin`, password `admin123` (demo credentials; change before deployment).
- Admin dashboard includes transaction/customer counts, paid revenue, admin display profile, and product create/edit/delete controls.
- If you are upgrading an existing database, back it up and run the new schema additions from `database.sql`; a fresh database import is recommended for this starter version.


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