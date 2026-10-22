# Van Project

Laravel 13 administration panel using PHP 8.3+, MySQL 8.0+, Blade and Tailwind CSS 4.

## Local setup

```sh
composer install
cp .env.example .env
php artisan key:generate
# Configure a dedicated MySQL database and credentials in .env.
php artisan migrate
npm ci --ignore-scripts
npm run build
php artisan serve
```

Never commit `.env`, generated application keys, credentials or database backups.

## Verification

```sh
composer validate --strict
vendor/bin/pint --test
php artisan test
npm run build
```

GitHub Actions runs migrations and tests against MySQL 8.4, checks PHP formatting and builds frontend assets. SQLite is available for fast local tests; MySQL CI remains required before merging.
