-- Dedicated database for the automated test suite. Runs only when the MySQL volume is first created.
CREATE DATABASE IF NOT EXISTS clinical_saas_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON clinical_saas_test.* TO 'clinical_user'@'%';
