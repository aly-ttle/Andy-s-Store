-- Remove duplicate unverified customer records while preserving verified accounts.
-- For an email with no verified account, the newest unverified customer is retained.
-- Duplicate unverified rows with orders are deliberately preserved for manual review.
-- Run once against the existing MySQL database; do not rerun andys_store.sql.

DROP TEMPORARY TABLE IF EXISTS duplicate_unverified_customers_to_delete;
CREATE TEMPORARY TABLE duplicate_unverified_customers_to_delete (
  id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB;

INSERT INTO duplicate_unverified_customers_to_delete (id)
SELECT id
FROM (
  SELECT
    u.id,
    u.email_verified_at,
    ROW_NUMBER() OVER (
      PARTITION BY LOWER(TRIM(u.email))
      ORDER BY (u.email_verified_at IS NOT NULL) DESC, u.created_at DESC, u.id DESC
    ) AS email_position,
    COUNT(*) OVER (PARTITION BY LOWER(TRIM(u.email))) AS email_count
  FROM users u
  WHERE u.role='customer'
) ranked
WHERE ranked.email_count > 1
  AND ranked.email_position > 1
  AND ranked.email_verified_at IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM orders o WHERE o.user_id=ranked.id
  );

-- Lists the exact customer rows selected for deletion for an audit trail.
SELECT u.id,u.name,u.email,u.created_at
FROM users u
JOIN duplicate_unverified_customers_to_delete d ON d.id=u.id
ORDER BY u.email,u.created_at,u.id;

START TRANSACTION;
DELETE u
FROM users u
JOIN duplicate_unverified_customers_to_delete d ON d.id=u.id
WHERE u.role='customer' AND u.email_verified_at IS NULL;
COMMIT;

DROP TEMPORARY TABLE duplicate_unverified_customers_to_delete;

-- Add a unique email index if possible. If verified or order-linked duplicates remain,
-- do not delete them automatically; inspect the report and resolve them manually.
SET @remaining_email_duplicate_groups = (
  SELECT COUNT(*)
  FROM (
    SELECT 1
    FROM users
    GROUP BY LOWER(TRIM(email))
    HAVING COUNT(*) > 1
  ) duplicate_groups
);

SET @unique_email_index_exists = (
  SELECT COUNT(*)
  FROM (
    SELECT index_name
    FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='users'
      AND non_unique=0
    GROUP BY index_name
    HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='email'
  ) unique_email_indexes
);

SET @email_index_statement = IF(
  @unique_email_index_exists > 0,
  'SELECT ''A unique email index already exists.'' AS result',
  IF(
    @remaining_email_duplicate_groups=0,
    'ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)',
    'SELECT ''Unique email index not added: duplicate emails remain; review the duplicate report.'' AS result'
  )
);

PREPARE email_index_statement FROM @email_index_statement;
EXECUTE email_index_statement;
DEALLOCATE PREPARE email_index_statement;

SELECT LOWER(TRIM(email)) AS normalized_email,COUNT(*) AS account_count,
       SUM(email_verified_at IS NOT NULL) AS verified_count,
       SUM(email_verified_at IS NULL) AS unverified_count
FROM users
GROUP BY LOWER(TRIM(email))
HAVING COUNT(*) > 1;
