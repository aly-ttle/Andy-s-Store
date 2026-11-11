-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 04, 2026 at 01:05 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `andys_store`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_accounts`
--

CREATE TABLE `admin_accounts` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(80) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `display_name` varchar(120) NOT NULL DEFAULT 'Store Administrator',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admin_accounts`
--

INSERT INTO `admin_accounts` (`id`, `username`, `password_hash`, `display_name`, `created_at`) VALUES
(1, 'admin', 'managed-by-local-demo-login', 'Store Administrator', '2026-11-03 02:01:47');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `icon` varchar(20) DEFAULT '?️',
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `slug`, `icon`, `is_active`) VALUES
(1, 'Beverages', 'beverages', '🥤', 1),
(2, 'Snacks', 'snacks', '🍿', 1),
(3, 'Meals', 'meals', '🍛', 1),
(4, 'Merienda', 'merienda', '🍜', 1),
(5, 'Ice Cream & Frozen Treats', 'frozen-treats', '🍦', 1);

-- --------------------------------------------------------

--
-- Table structure for table `email_verification_tokens`
--

CREATE TABLE `email_verification_tokens` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `status` enum('pending','confirmed','preparing','ready','completed','cancelled') NOT NULL DEFAULT 'pending',
  `notes` varchar(500) DEFAULT NULL,
  `payment_method` varchar(40) NOT NULL DEFAULT 'Pay at cashier',
  `payment_status` enum('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_name` varchar(150) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_codes`
--

CREATE TABLE `password_reset_codes` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `category_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `badge` varchar(40) DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `product_images`
--

CREATE TABLE `product_images` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `image_url` varchar(500) NOT NULL,
  `sort_order` tinyint(3) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `product_options`
--

CREATE TABLE `product_options` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `option_name` varchar(80) NOT NULL,
  `price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `price`, `image_url`, `badge`, `is_available`, `created_at`) VALUES
(1, 3, 'Banana Que', 'Unit: 1 serving', 25.00, 'products/banana.png', NULL, 1, '2026-11-03 02:01:46'),
(2, 3, 'Egg with Amplaya', 'Unit: 1 serving', 60.00, 'products/product_c54e128fdd2f2a20a82a2dd3.jpg', NULL, 1, '2026-11-03 02:01:46'),
(3, 3, 'Pork Steak', 'Unit: 1 serving', 65.00, 'products/pork steak.jpg', NULL, 1, '2026-11-03 02:01:46'),
(4, 3, 'Lechon Kawali', 'Unit: 1 serving', 75.00, 'products/lechon kawali.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(5, 3, 'Sisig', 'Unit: 1 serving', 70.00, 'products/sisig.jpg', NULL, 1, '2026-11-03 02:01:46'),
(6, 3, 'Dinakdakan', 'Unit: 1 serving', 70.00, 'products/dinakdakan.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(7, 3, 'Dinuguan', 'Unit: 1 serving', 60.00, 'products/dinuguan.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(8, 3, 'Menudo', 'Unit: 1 serving', 60.00, 'products/menudo.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(9, 3, 'Longanisa', 'Unit: 3 pieces', 45.00, 'products/longanisa.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(10, 3, 'Hotdog', 'Unit: 2 pieces', 30.00, 'products/hotdog.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(11, 3, 'Sunny Side Up Egg', 'Unit: 1 piece', 15.00, 'products/product_75e552f95e3d095219459d6c.jpg', NULL, 1, '2026-11-03 02:01:46'),
(12, 3, 'Fried Tilapia', 'Unit: 1 piece', 55.00, 'products/fried fish tilapia.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(13, 3, 'Chicken Curry', 'Unit: 1 serving', 60.00, 'products/curry.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(14, 3, 'Chopsuey', 'Unit: 1 serving', 45.00, 'products/chopsuey.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(15, 3, 'Pakbet', 'Unit: 1 serving', 40.00, 'products/pakbet.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(16, 3, 'Ginisang karne with sitaw', 'Unit: 1 serving', 35.00, 'products/karne with sitaw.png', NULL, 1, '2026-11-03 02:01:46'),
(17, 3, 'Spam', 'Unit: 1 serving', 35.00, 'products/spam.jpg', NULL, 1, '2026-11-03 02:01:46'),
(18, 4, 'Lugaw', 'Unit: 1 bowl', 30.00, 'products/lugaw.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(19, 4, 'Pansit', 'Unit: 1 serving', 40.00, 'products/pansit.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(20, 3, 'Cup Noodles (Batchoy)', 'Unit: 1 cup', 30.00, 'products/cup noodles.jpg', NULL, 1, '2026-11-03 02:01:46'),
(21, 4, 'Kwek Kwek', 'Unit: 5 pieces', 25.00, 'products/kwek.jpg', NULL, 1, '2026-11-03 02:01:46'),
(22, 4, 'Siomai', 'Unit: 4 pieces', 30.00, 'products/siomai.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(23, 4, 'Lumpiang Shanghai', 'Unit: 5 pieces', 35.00, 'products/lumpiang shanghai.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(24, 4, 'Lumpiang Gulay', 'Unit: 3 pieces', 25.00, 'products/lumpiang gulay.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(25, 4, 'French Fries', 'Unit: 1 cup', 35.00, 'products/fries.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(26, 2, 'Lay\'s Salted', 'Unit: 1 pack', 30.00, 'products/lays.jpg', NULL, 1, '2026-11-03 02:01:46'),
(27, 2, 'Mr. Chips Nachos', 'Unit: 1 pack', 15.00, 'products/mr. chips-nachos.jpg', NULL, 1, '2026-11-03 02:01:46'),
(28, 2, 'Mr. Chips Sweet Corn', 'Unit: 1 pack', 15.00, 'products/mr. chips-sweet corn.jpg', NULL, 1, '2026-11-03 02:01:46'),
(29, 2, 'Criss Cross Creamy Cheese', 'Unit: 1 pack', 20.00, 'products/criss cross.jpg', NULL, 1, '2026-11-03 02:01:46'),
(30, 2, 'Cheese Ball', 'Unit: 1 pack', 12.00, 'products/cheese ball.jpg', NULL, 1, '2026-11-03 02:01:46'),
(31, 2, 'Cheese Ring', 'Unit: 1 pack', 12.00, 'products/cheese ring.jpg', NULL, 1, '2026-11-03 02:01:46'),
(32, 2, 'Oishi Cracklings (Salt & Vinegar)', 'Unit: 1 pack', 15.00, 'products/cracklings.jpg', NULL, 1, '2026-11-03 02:01:46'),
(33, 2, 'Marty\'s Cracklin\' (Spicy Vinegar)', 'Unit: 1 pack', 15.00, 'products/martys.jpg', NULL, 1, '2026-11-03 02:01:46'),
(34, 2, 'Chicharron ni Mang Juan (Vinegar)', 'Unit: 1 pack', 15.00, 'products/mang juan-vinegar.jpg', NULL, 1, '2026-11-03 02:01:46'),
(35, 2, 'Nova Multigrain Snack (BBQ)', 'Unit: 1 pack', 15.00, 'products/nova.jpg', NULL, 1, '2026-11-03 02:01:46'),
(36, 2, 'Oishi Prawn Crackers (Spicy)', 'Unit: 1 pack', 12.00, 'products/oishi.jpg', NULL, 1, '2026-11-03 02:01:46'),
(37, 2, 'Oishi Crispy Patata', 'Unit: 1 pack', 12.00, 'products/patata.jpg', NULL, 1, '2026-11-03 02:01:46'),
(38, 2, 'Oishi Pillows (Choco-filled)', 'Unit: 1 pack', 12.00, 'products/pillows.jpg', NULL, 1, '2026-11-03 02:01:46'),
(39, 2, 'Snacku Rice Crackers (Veggie)', 'Unit: 1 pack', 12.00, 'products/snacku.jpg', NULL, 1, '2026-11-03 02:01:46'),
(40, 2, 'Regent Golden Sweet Corn', 'Unit: 1 pack', 12.00, 'products/sweet corn.jpg', NULL, 1, '2026-11-03 02:01:46'),
(41, 2, 'Regent Tempura (Shrimp)', 'Unit: 1 pack', 12.00, 'products/tempura.jpg', NULL, 1, '2026-11-03 02:01:46'),
(42, 2, 'Fudgee Barr', 'Unit: 1 piece', 12.00, 'products/fudgee bar.jpg', NULL, 1, '2026-11-03 02:01:46'),
(43, 2, 'Hello! Chocolate Wafer', 'Unit: 1 piece', 10.00, 'products/hello.jpg', NULL, 1, '2026-11-03 02:01:46'),
(44, 1, 'Pepsi', 'Unit: 1 bottle', 25.00, 'products/pepsi.jpg', NULL, 1, '2026-11-03 02:01:46'),
(45, 1, 'Coca-Cola (Bottle)', 'Unit: 500 ml bottle', 25.00, 'products/coca-cola bottled.jpg', NULL, 1, '2026-11-03 02:01:46'),
(46, 1, 'Coca-Cola Zero Sugar (Can)', 'Unit: 1 can', 35.00, 'products/coca-cola.jpg', NULL, 1, '2026-11-03 02:01:46'),
(47, 1, 'Sprite', 'Unit: 1 bottle', 25.00, 'products/product_6a17db9903972309b8a923fc.jpg', NULL, 1, '2026-11-03 02:01:46'),
(48, 1, 'Royal', 'Unit: 1 bottle', 25.00, 'products/royal.jpg', NULL, 1, '2026-11-03 02:01:46'),
(49, 1, 'Mountain Dew', 'Unit: 1 bottle', 25.00, 'products/mt. dew.jpg', NULL, 1, '2026-11-03 02:01:46'),
(50, 1, 'Gatorade (Blue)', 'Unit: 500 ml bottle', 40.00, 'products/product_210298fd6433fac7cf5fb51e.jpg', NULL, 1, '2026-11-03 02:01:46'),
(51, 1, 'C2 Green Tea', 'Unit: 1 bottle', 25.00, 'products/c2-green tea.jpg', NULL, 1, '2026-11-03 02:01:46'),
(52, 1, 'C2 Apple Green Tea', 'Unit: 1 bottle', 25.00, 'products/c2-red.jpg', NULL, 1, '2026-11-03 02:01:46'),
(53, 1, 'Mogu Mogu (Lychee)', 'Unit: 1 bottle', 35.00, 'products/mogu-mogu.jpg', NULL, 1, '2026-11-03 02:01:46'),
(54, 1, 'Chuckie', 'Unit: 1 pack', 20.00, 'products/product_68ff5c9d2e31190eb126cfb7.jpg', NULL, 1, '2026-11-03 02:01:46'),
(55, 1, 'Vitamilk', 'Unit: glass bottle', 25.00, 'products/vitamilk.jpg', NULL, 1, '2026-11-03 02:01:46'),
(56, 1, 'Yakult', 'Unit: 1 bottle', 12.00, 'products/yakult.jpg', NULL, 1, '2026-11-03 02:01:46'),
(57, 5, 'Cornetto (Oreo)', 'Unit: 1 cone', 35.00, 'products/cornetto.jpg', NULL, 1, '2026-11-03 02:01:46'),
(58, 4, 'Ube Turon Malagkit', 'Unit: 1 piece', 25.00, 'products/product_5019b079194b25a035f63d57.png', NULL, 1, '2026-11-03 02:01:46'),
(59, 1, '7UP', '', 25.00, 'products/7UP.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(60, 1, 'Bear Brand', '', 40.00, 'products/bearbrand.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(61, 1, 'Cali Apple', 'Set a price before making this item available.', 0.00, 'products/Cali-apple.jpg', NULL, 0, '2026-11-03 02:01:46'),
(62, 1, 'Cali Pineapple', 'Set a price before making this item available.', 35.00, 'products/Cali-pineapple.jpg', NULL, 0, '2026-11-03 02:01:46'),
(63, 1, 'Del Monte Green', '', 40.00, 'products/delmonte-green.jpg', NULL, 1, '2026-11-03 02:01:46'),
(64, 1, 'Del Monte Orange', '', 40.00, 'products/delmonte-orange.jpg', NULL, 1, '2026-11-03 02:01:46'),
(65, 1, 'Del Monte Yellow', '', 40.00, 'products/delmonte-yellow.jpg', NULL, 1, '2026-11-03 02:01:46'),
(66, 1, 'Dutch Mill Apple', '', 15.00, 'products/dutch mill apple.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(67, 1, 'Dutch Mill', '', 15.00, 'products/dutchmill.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(68, 1, 'Milo', '', 15.00, 'products/milo.jpeg', NULL, 1, '2026-11-03 02:01:46'),
(69, 1, 'Minute Maid Apple Juice', '', 40.00, 'products/minute maid apple.jpg', NULL, 1, '2026-11-03 02:01:46'),
(70, 1, 'Minute Maid Orange Juice', '', 40.00, 'products/minute maid.jpg', NULL, 1, '2026-11-03 02:01:46'),
(71, 1, 'Minute Maid Pineapple Orange', '', 40.00, 'products/MinuteMaid-yellow.jpg', NULL, 1, '2026-11-03 02:01:46'),
(72, 1, 'Minute Maid Orange Can', '', 40.00, 'products/MinuteMaid.jpg', NULL, 1, '2026-11-03 02:01:46'),
(73, 1, 'Nescafe Cappuccino', '', 35.00, 'products/Nescafe-cappuccino.jpg', NULL, 1, '2026-11-03 02:01:46'),
(74, 1, 'Nescafe Ice Black', '', 35.00, 'products/Nescafe-ice black.jpg', NULL, 1, '2026-11-03 02:01:47'),
(75, 1, 'Nescafe Mocha', '', 35.00, 'products/Nescafe-mocha.jpg', NULL, 1, '2026-11-03 02:01:47'),
(76, 1, 'NutriBoost Choco', '', 35.00, 'products/nutriboost-choco.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(77, 1, 'NutriBoost Strawberry', '', 35.00, 'products/nutriboost-strawberry.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(78, 1, 'Rite n Lite', '', 35.00, 'products/Rite n Lite.jpg', NULL, 1, '2026-11-03 02:01:47'),
(79, 1, 'Soursop Guyabano', '', 40.00, 'products/Soursop-guyabano.jpg', NULL, 1, '2026-11-03 02:01:47'),
(80, 1, 'Soursop Mangosteen', '', 40.00, 'products/Soursop-mangosteen.jpg', NULL, 1, '2026-11-03 02:01:47'),
(81, 1, 'Tropicana Orange', '', 15.00, 'products/tropicana orange.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(82, 1, 'Vitamilk Banana', '', 40.00, 'products/vitamilk banana.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(83, 1, 'Vitamilk Choco', '', 40.00, 'products/vitamilk choco.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(84, 1, 'Vitamilk Ube', '', 40.00, 'products/vitamilk ube.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(85, 3, 'Chicken Adobo', '', 40.00, 'products/chicken adobo.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(86, 3, 'Fried Chicken', '', 40.00, 'products/fried chicken.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(87, 3, 'Ginisang Upo', '', 35.00, 'products/ginisang upo.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(88, 3, 'Pork Adobo', '', 40.00, 'products/pork adobo.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(89, 3, 'Vegetable Salad', '', 40.00, 'products/vegetable salad.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(90, 4, 'Hashbrown', '', 15.00, 'products/hashbrown.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(91, 4, 'Ube Malagkit', 'Set a price before making this item available.', 5.00, 'products/ube malagkit.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(92, 2, 'Yema', 'Set a price before making this item available.', 10.00, 'products/yema.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(93, 1, 'Greek Yogurt', '', 30.00, 'products/greek-yogurt.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(94, 1, 'Magnolia Apple', 'Set a price before making this item available.', 12.00, 'products/magnolia apple.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(95, 1, 'Magnolia Mango', 'Set a price before making this item available.', 12.00, 'products/magnolia mango.jpeg', NULL, 1, '2026-11-03 02:01:47'),
(97, 1, 'royal', 'sparkling and refreshing dring', 15.00, 'products/product_47360705a49bd6e6643ef5a0.jpg', NULL, 1, '2026-11-03 02:39:38');

--
-- Seed legacy meal prices as editable price options.
--

INSERT INTO `product_options` (`id`, `product_id`, `option_name`, `price`)
SELECT p.id * 2, p.id, 'Meal with rice', p.price
FROM products p JOIN categories c ON c.id=p.category_id
WHERE c.slug='meals';

INSERT INTO `product_options` (`id`, `product_id`, `option_name`, `price`)
SELECT p.id * 2 + 1, p.id, 'Meal only', p.price
FROM products p JOIN categories c ON c.id=p.category_id
WHERE c.slug='meals';

INSERT INTO `product_images` (`product_id`, `image_url`, `sort_order`)
SELECT id, image_url, 0
FROM products
WHERE image_url IS NOT NULL AND image_url<>'';

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('customer','admin') NOT NULL DEFAULT 'customer',
  `account_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `student_id` varchar(40) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `school_type` enum('university','lhs') NOT NULL DEFAULT 'university',
  `location_address` varchar(255) DEFAULT NULL,
  `course` varchar(150) DEFAULT NULL,
  `year_level` varchar(30) DEFAULT NULL,
  `strand` varchar(40) DEFAULT NULL,
  `section` varchar(50) DEFAULT NULL,
  `profile_picture` varchar(500) DEFAULT NULL,
  `wallet_balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_accounts`
--
ALTER TABLE `admin_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `email_verification_tokens`
--
ALTER TABLE `email_verification_tokens`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_orders_user` (`user_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_items_order` (`order_id`),
  ADD KEY `fk_items_product` (`product_id`);

--
-- Indexes for table `password_reset_codes`
--
ALTER TABLE `password_reset_codes`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_products_category` (`category_id`);

--
-- Indexes for table `product_options`
--
ALTER TABLE `product_options`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_product_option` (`product_id`,`option_name`);

--
-- Indexes for table `product_images`
--
ALTER TABLE `product_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_product_images_product` (`product_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_accounts`
--
ALTER TABLE `admin_accounts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=98;

--
-- AUTO_INCREMENT for table `product_options`
--
ALTER TABLE `product_options`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_images`
--
ALTER TABLE `product_images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `email_verification_tokens`
--
ALTER TABLE `email_verification_tokens`
  ADD CONSTRAINT `fk_email_verification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `password_reset_codes`
--
ALTER TABLE `password_reset_codes`
  ADD CONSTRAINT `fk_password_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_options`
--
ALTER TABLE `product_options`
  ADD CONSTRAINT `fk_product_options_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_images`
--
ALTER TABLE `product_images`
  ADD CONSTRAINT `fk_product_images_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
