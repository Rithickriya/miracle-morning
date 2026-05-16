# Miracle Morning Registration Desk

PHP/MySQL registration and admin dashboard for member, visitor, observer, kitty/payment tracking, weekly Sunday reporting, printable reports, PDF-style print views, imports, and business card uploads.

## Requirements

- PHP 8.1 or newer recommended
- MySQL or MariaDB
- Composer
- Apache with PHP, such as XAMPP locally or your production hosting

## Setup

1. Install PHP dependencies:
   ```bash
   composer install
   ```
2. Create a MySQL database.
3. Import `database_schema.sql` if you are setting up a fresh database.
4. Copy `api/db_config.example.php` to `api/db_config.php` and enter your database credentials.
5. Copy `api/credentials.example.php` to `api/credentials.php` and set strong admin/desk passwords.
6. Make sure `api/uploads/cards/` exists and is writable by PHP.
7. Open `/register.php` for the desk registration screen or `/api/login.php` for admin login.

## Data Safety

Do not commit real client/member/visitor/payment data. These files and folders are intentionally ignored:

- `api/db_config.php`
- `api/credentials.php`
- `api/uploads/`
- database dumps and generated exports/reports

The existing database column `friday_date` is kept for compatibility with the current app database. In this Miracle Morning version, that column stores the Sunday meeting/report date.

## GitHub Upload

Before pushing, confirm:

- `api/db_config.php` and `api/credentials.php` are not staged.
- `api/uploads/` is not staged.
- No generated PDF, ZIP, Excel, CSV, SQL dump, backup, or log file is staged.
- Run a final search for private credentials or client data.
