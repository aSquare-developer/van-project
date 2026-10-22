# Van Project — Laravel Admin Panel

A server-rendered administration panel built with Laravel 13, MySQL, Blade and Tailwind CSS 4.

## Features

- Email/password session authentication and email password recovery; no public registration.
- Three fixed roles: Admin, Moderator, User. Only Admin can create/manage accounts, assign roles, block/unblock or view the activity log.
- Responsive dashboard, account profile, user search and pagination.
- Session revocation after password, email, role and status changes.
- Protection against self-block/delete and loss of the last active administrator, including simultaneous updates.
- Audit log without passwords, tokens or other sensitive values.

## Local setup

Requires patched PHP 8.3+ with standard Laravel extensions and pdo_mysql, Composer 2, MySQL 8.0+ and Node 24.

```sh
composer install
cp .env.example .env
php artisan key:generate
# Set a dedicated MySQL database and credentials in .env.
php artisan migrate
php artisan admin:create
npm ci --ignore-scripts
npm run build
php artisan serve
```

The admin command asks for name/email and a hidden password and confirmation. It only creates the first active administrator. Additional users are created through User management. No demo accounts are seeded.

Local reset emails appear in `storage/logs/laravel.log` with `MAIL_MAILER=log`. Configure SMTP for actual email delivery. Real `.env` files, keys, credentials and database backups must never be committed.

## Verification

```sh
composer validate --strict
composer audit --locked
vendor/bin/pint --test
php artisan test
npm audit
npm run build
```

Tests default to in-memory SQLite. The two-process administrator concurrency test requires MySQL and is skipped on SQLite. Set `DB_CONNECTION=mysql`, `DB_DATABASE` and other `DB_*` variables to a dedicated disposable test database to run the full suite. Tests migrate and delete database data: never point them at production.

GitHub Actions checks PHP 8.3, 8.4 and 8.5 against MySQL 8.4, runs PHP syntax/style checks, dependency audits, migrations, tests, frontend build and configuration/route/view compilation. No merge should bypass a failing check.

## Documentation

- [Architecture and access matrix](docs/architecture.md)
- [SEC-001–SEC-007 security coverage](docs/security.md)
- [Deployment and operation](docs/deployment.md)

The code is prepared for deployment. Server provisioning, production credentials, TLS and real SMTP delivery must be configured and verified in the target environment.
