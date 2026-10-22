# Deployment

## Requirements

- A currently patched PHP 8.3, 8.4 or 8.5 with ctype, curl, dom, fileinfo, filter, hash, mbstring, openssl, PDO/pdo_mysql, session, tokenizer, xml and zip.
- MySQL 8.0+ with InnoDB. CI uses MySQL 8.4. Local integration testing also uses MariaDB 10.11.
- Node 24 for asset building; Composer 2; HTTPS; SMTP provider.
- Web document root must point to `public/`. Only `storage/` and `bootstrap/cache/` need application write access. Do not use permission mode 777.

## Initial deployment

1. Deploy a commit that has passed CI. Install production PHP packages with `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader`.
2. Build assets in CI or a build environment with `npm ci --ignore-scripts && npm run build`; deliver `public/build/` with the release. The web server does not need Node or node_modules.
3. Copy `.env.example` to `.env` on the server and set the values below. Keep `.env` out of Git, backups exposed to the web and CI logs. Run `php artisan key:generate` once on first installation. Preserve that key on subsequent releases.
4. Create the dedicated database and configure credentials. Use a deployment database principal for migrations and, if available, a restricted runtime principal for normal operation.
5. Run `php artisan migrate --force` after taking a database backup. Do not run reset/fresh commands on production data.
6. Run `php artisan admin:create --name="Administrator" --email="your-email@example.com"` interactively. Enter and confirm a unique strong password at the hidden prompts. The command refuses when an active admin already exists. No default credentials or accounts are seeded.
7. Run `php artisan config:cache`, `php artisan route:cache` and `php artisan view:cache`. Restart PHP workers if needed. There are no scheduled tasks or queue workers required for the current synchronous reset email flow.
8. Verify `/up`, login/logout, user creation, each role, blocking an already signed-in account and password reset through your real mailbox. Confirm HTTP redirects to HTTPS, no debug output, and that `/.env` and `/vendor/` cannot be served.

## Production environment

```dotenv
APP_NAME="Van Project"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://van.asquare.ee
APP_TIMEZONE=Europe/Tallinn
# APP_KEY is generated on the server and kept secret.
DB_CONNECTION=mysql
# Set DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD on the server.
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=smtp
# Set MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD and MAIL_SCHEME per provider.
# Common provider configurations: smtp on port 587 with STARTTLS, or smtps on 465.
MAIL_FROM_ADDRESS=noreply@your-domain.example
MAIL_FROM_NAME="Van Project"
```

Use a verified sender domain with the provider's SPF/DKIM records. The default local mailer writes reset links to local logs; it is intended for development. Never use `MAIL_MAILER=log` in production. The reset URL comes from APP_URL, so this value must be the public HTTPS origin. Behind a reverse proxy, configure only the actual trusted proxy addresses and preserve the original HTTPS scheme; do not trust arbitrary forwarded headers.

## Updates, rollback and operations

Deploy through release directories with an atomic symlink switch where hosting permits. Share `.env` and `storage/` between releases. Take an encrypted database backup before migrations, retain the application key separately, and regularly test restoration. Backups include user data and authentication material and require restricted access.

For an application-only rollback, restore the preceding tested release. Database rollbacks require review of the migration and data impact; restoring the pre-deployment backup may be necessary. Do not automatically run migration rollback on production failure.

Monitor application errors, SMTP failures, database availability and disk use. Set an audit-log retention policy before long-term use; there is deliberately no web UI for deleting audit records. Configure session cleanup through the database session driver's garbage collection, and retain centralized server logs according to your operational policy.

In GitHub repository settings, require the CI `test` matrix checks before merging and restrict direct pushes to main. These repository administration settings are not changed by this implementation. Keep provider/server credentials in the hosting secret store, never in issue or PR comments.
