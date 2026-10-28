-- Second database for the test suite, so the tests never wipe the workbench data.
CREATE DATABASE IF NOT EXISTS waypost_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON waypost_test.* TO 'waypost'@'%';
