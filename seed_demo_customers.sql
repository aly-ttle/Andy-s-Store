-- Optional one-time seed: inserts 25 synthetic, unverified customer records.
-- These use reserved .invalid email addresses and cannot receive email or sign in.
-- Run against the production database only if you want demo rows in the live directory.
-- INSERT IGNORE makes this safe to re-run when the users.email unique index is present.

INSERT IGNORE INTO users
  (name,email,password_hash,role,account_status,phone,school_type,course,year_level,strand,section)
VALUES
  ('Demo Customer 01','demo.customer01@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000001','university','BS Information Technology','1st Year',NULL,'DEMO-01'),
  ('Demo Customer 02','demo.customer02@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000002','university','BS Computer Science','2nd Year',NULL,'DEMO-02'),
  ('Demo Customer 03','demo.customer03@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000003','university','BS Business Administration','3rd Year',NULL,'DEMO-03'),
  ('Demo Customer 04','demo.customer04@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000004','university','BS Accountancy','4th Year',NULL,'DEMO-04'),
  ('Demo Customer 05','demo.customer05@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000005','university','BA Social Science','3rd Year',NULL,'DEMO-05'),
  ('Demo Customer 06','demo.customer06@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000006','university','BS Education','2nd Year',NULL,'DEMO-06'),
  ('Demo Customer 07','demo.customer07@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000007','university','BS Hospitality Management','1st Year',NULL,'DEMO-07'),
  ('Demo Customer 08','demo.customer08@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000008','university','BS Psychology','5th Year',NULL,'DEMO-08'),
  ('Demo Customer 09','demo.customer09@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000009','university','BS Biology','2nd Year',NULL,'DEMO-09'),
  ('Demo Customer 10','demo.customer10@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000010','university','BS Nursing','4th Year',NULL,'DEMO-10'),
  ('Demo Customer 11','demo.customer11@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000011','university','BS Engineering','3rd Year',NULL,'DEMO-11'),
  ('Demo Customer 12','demo.customer12@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000012','university','BA Communication','1st Year',NULL,'DEMO-12'),
  ('Demo Customer 13','demo.customer13@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000013','university','BS Criminology','2nd Year',NULL,'DEMO-13'),
  ('Demo Customer 14','demo.customer14@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000014','university','BS Architecture','5th Year',NULL,'DEMO-14'),
  ('Demo Customer 15','demo.customer15@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000015','university','BS Tourism','4th Year',NULL,'DEMO-15'),
  ('Demo Customer 16','demo.customer16@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000016','lhs','Junior High School','Grade 7',NULL,'DEMO-16'),
  ('Demo Customer 17','demo.customer17@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000017','lhs','Junior High School','Grade 8',NULL,'DEMO-17'),
  ('Demo Customer 18','demo.customer18@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000018','lhs','Junior High School','Grade 9',NULL,'DEMO-18'),
  ('Demo Customer 19','demo.customer19@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000019','lhs','Junior High School','Grade 10',NULL,'DEMO-19'),
  ('Demo Customer 20','demo.customer20@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000020','lhs','Senior High School','Grade 11','HUMSS','DEMO-20'),
  ('Demo Customer 21','demo.customer21@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000021','lhs','Senior High School','Grade 11','ABM','DEMO-21'),
  ('Demo Customer 22','demo.customer22@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000022','lhs','Senior High School','Grade 11','STEM','DEMO-22'),
  ('Demo Customer 23','demo.customer23@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000023','lhs','Senior High School','Grade 12','HUMSS','DEMO-23'),
  ('Demo Customer 24','demo.customer24@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000024','lhs','Senior High School','Grade 12','ABM','DEMO-24'),
  ('Demo Customer 25','demo.customer25@example.invalid','$2y$10$kUBstkflzHRxzt1HiPW8ueZ/xVnceZstbKGg8C4phxmbiqv7hHdLe','customer','approved','00000000025','lhs','Senior High School','Grade 12','STEM','DEMO-25');

SELECT COUNT(*) AS synthetic_demo_customers
FROM users
WHERE role='customer' AND email LIKE 'demo.customer%@example.invalid';
