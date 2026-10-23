# Deployment

## Zone shared hosting setup

Deployment target: `virt141509@asquare.ee`, root `/data02/virt141509/domeenid/www.asquare.ee/van`.
Set the `van.asquare.ee` document root to `/data02/virt141509/domeenid/www.asquare.ee/van/public` and enable HTTPS and PHP 8.4 for that host. The workflow uses Zone's explicit `php84-cli` command, so it does not change the PHP version of the main domain or other sites.

The layout is:

```text
van/.env                 server-only production configuration
van/.van-project         marker containing asquare/van-project
van/storage/             persistent application data
van/releases/<release>/  source, vendor and compiled frontend
van/current              symlink to the active release
van/public               symlink to current/public
```

Before the first run, create the marker in the confirmed empty project directory:

```sh
cd /data02/virt141509/domeenid/www.asquare.ee/van
printf '%s\n' 'asquare/van-project' > .van-project
```

Create the production `.env` in that same directory using the settings below. Generate its APP_KEY once with `php84-cli -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'` and put the result in the file. Keep it private and never replace it during updates. Set `.env` permissions to 600. Configure a dedicated MySQL database, database sessions with encryption and an SMTP sender before activating the site. The workflow refuses missing or development configuration without publishing the new release.

Repository Actions secrets:

| Name | Value |
| --- | --- |
| SSH_PRIVATE_KEY | The existing dedicated van-project private key |
| SSH_KNOWN_HOSTS | Trusted OpenSSH known_hosts entry for asquare.ee |

After verifying the host fingerprint against Zone's server-key fingerprints, copy the existing trusted entry on your Mac with `ssh-keygen -F asquare.ee | pbcopy`. Store that output in SSH_KNOWN_HOSTS. The workflow uses strict host-key verification and never fetches and trusts a new key automatically.

Merge only after CI passes, then open **Actions → Deploy to Zone → Run workflow → main**. Deployment is manual; merging a PR does not publish it. The workflow checks the three successful PHP CI jobs for the exact main commit, builds locked dependencies/assets and sends a new archive over SSH. The server keeps `.env` and `storage` outside releases, locks against concurrent deploys, briefly enters maintenance mode for migrations and switches the `current` symlink after caches compile. The final check requests the public HTTPS `/up` endpoint.

No database rollback or deletion of old releases is automatic. A failed migration leaves the previous code selected and attempts to exit maintenance, but database changes can still require manual recovery. Take a database backup before updates and review schema compatibility. A failed public health check after activation requires checking DNS, TLS, document root and the application; it does not automatically revert the database or code. Uploaded archives and old releases are retained for inspection; remove selected old releases after verifying the new version and disk use.

After the first successful deployment:

```sh
cd /data02/virt141509/domeenid/www.asquare.ee/van/current
php84-cli artisan admin:create
```

The password is entered at a hidden terminal prompt. Verify login, role restrictions, blocking and password reset through your real email provider before putting the panel into use.

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
7. Run `php artisan config:cache`, `php artisan route:cache` and `php artisan view:cache`. Restart PHP workers if needed. Password-reset email remains synchronous; photo analysis requires the scheduler and inspection queue worker below.
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

## Photo analysis service and Zone queue

For `VISION_PROVIDER=custom` (the backwards-compatible default), set `VISION_ENDPOINT` to the full trusted HTTPS endpoint, `VISION_TOKEN` to its server-only bearer token and `VISION_SILICONE_MODEL_VERSION` to the deployed, immutable model version. No credentials, model or service endpoint are provided by this repository. This is a custom version-1 contract, not a direct Roboflow API adapter: the separate service must implement the contract in architecture.md and can run a Roboflow model internally. Only configure a service authorized to receive these private photographs. Disable request/response body and authorization-header logging there too.

Rebuild config cache after changing server configuration. New attempts snapshot the check key, expected/empty classes, confidence threshold and model version. Changing configuration does not silently reinterpret old attempts; submit a new photo for a new model/configuration. Missing configuration records an analysis error, never a fabricated recognition result.

Analysis uses the **database** queue connection and `inspections` queue regardless of the default queue driver. Keep `DB_QUEUE_CONNECTION` unset so job inserts and the dispatch marker share the application's transaction; keep `queue.connections.database.after_commit=false` and `DB_QUEUE_RETRY_AFTER=90` (or greater than the 50-second job timeout). Use the existing jobs/failed_jobs migrations. Check `php84-cli -m` for pcntl before relying on worker-enforced timeouts; HTTP also has its own 5-second connection and 30-second total timeout.

After deploying and configuring the service, add a once-per-minute Zone cron task with working directory `/data02/virt141509/domeenid/www.asquare.ee/van/current`. Use the host-resolved absolute path to `php84-cli` if cron does not provide it in PATH:

```sh
php84-cli artisan schedule:run && php84-cli artisan queue:work database --queue=inspections --stop-when-empty --max-time=50 --timeout=50 --tries=1 --sleep=1
```

Capture cron output in restricted hosting logs and monitor it; do not discard failures. The scheduler queues at most 100 pending/recoverable attempts per minute. The short-lived worker processes committed jobs and reloads current code on its next invocation, so no permanent daemon is required. Backlogs require more worker capacity. A dedicated supervised worker is also possible; restart it on each release.

The durable attempt row acts as an outbox: dispatch failure does not roll back an already uploaded image. A two-minute lease recovers undelivered jobs and crashed workers. Duplicate deliveries cannot claim an active lease; request idempotency uses the same attempt UUID across at most three service calls. The service must persist/deduplicate that UUID together with image hash/check/model, rejecting reuse for different inputs. Transient connection/429/5xx failures wait at least 30 then 60 seconds and are requeued by the scheduler; other HTTP failures and invalid contracts are terminal. Do not use `queue:retry all` as the application retry mechanism. A new photo creates a new independent attempt.

Verify on the server: one stocked holder, one empty holder, one unclear image, service unavailable, repeated delivery and a new photo overtaking an old result. Confirm the worker drains the queue and no bearer token or raw service response appears in application/failed-job logs. Automated tests use HTTP fakes only; real endpoint/model acceptance and cron execution remain deployment acceptance checks (#25). No cron or service is activated automatically by merging.


## Direct OpenAI provider: configuration and acceptance

This adapter removes the need to host a separate v1 service for the pilot. It does not train a detector and is not live camera inference. Configure privately on Zone, never in GitHub issues, browser forms or frontend JavaScript:

```dotenv
VISION_PROVIDER=openai
OPENAI_API_KEY=<dedicated-project-key>
VISION_OPENAI_MODEL=<immutable-model-snapshot-supporting-images-and-structured-outputs>
VISION_OPENAI_AUTOMATIC_DECISIONS=false
```

There is deliberately no default model alias. Choose an available model snapshot with image input and strict JSON Schema support in Responses; record the exact ID used for acceptance. `VISION_ENDPOINT`, `VISION_TOKEN` and `VISION_SILICONE_MODEL_VERSION` are used only by custom attempts. Keep those credentials available until previously queued custom attempts finish. New OpenAI attempts use `VISION_OPENAI_MODEL` as their model version. Configure an appropriate PHP memory limit for two maximum-size images plus JSON/base64 encoding; 256M is a practical starting point to verify on Zone.

1. Merge/deploy after CI, apply the additive migration, add a reference photograph to the silicone-holder template and assign a new shift. Already assigned checks retain their old references; checks without one cannot use this adapter.
2. Set a dedicated provider project budget/alerts and verify its actual spending controls. Each attempt makes at most three requests, but an interrupted request may still be billed; the idempotency header is not a guarantee of provider-side billing deduplication. Verify upload throttling and expected daily photograph volume before wider use. `max_output_tokens=800` bounds generated output, not image-input cost. Do not log HTTP bodies/authorization headers, including in external observability tooling.
3. Run `php84-cli artisan config:cache`, then configure the existing scheduler and database inspection worker above. No extra public photo route or Node/Python service is required. Do not transfer real private photographs until provider data processing/retention settings meet your requirements; `store=false` disables stored Responses state, not all possible abuse-monitoring retention.
4. Submit a small authorized pilot set. Verify private-photo access, queue processing, normalized history, escaped explanation and advisory/inconclusive behavior in the UI. Verify a missing key, bad model, missing reference, 401, 429 and unavailable network never produce a pass or an empty-holder finding. Check real response latency against the 30-second HTTP timeout.
5. Build a human-labelled set covering empty holder, one cartridge, several cartridges, a cartridge outside the holder, similar objects, obstructed/missing holder, wrong area, dim light and varying angles/devices. Include at least 10 independent photos per case as a starting pilot set, keep a held-out set and measure false acceptance, false rejection and inconclusive rate. These numbers are engineering starting points, not an accuracy guarantee. Agree the acceptable false-acceptance rate before promoting; a reported 0.95 confidence alone is insufficient.
6. Keep automatic decisions disabled until the held-out results justify them. If accepted, set `VISION_OPENAI_AUTOMATIC_DECISIONS=true`, rebuild config cache and submit NEW attempts; advisory records stay advisory. Disable the flag/rebuild config cache to suppress new automatic outcomes during an incident. Existing finalized results are not retroactively changed. To change provider, set `VISION_PROVIDER=custom` and configure the v1 service; queued OpenAI attempts keep their provider and still require their key.

Implementation and activation are separate: no production API key, paid request, remote provider project, cron job or real-photo validation is provisioned by the code change. Continue tracking live acceptance under #25 and provider integration under #31. If general vision fails the held-out acceptance threshold, collect/label a dedicated dataset and connect a trained detector through the custom-v1 contract rather than weakening the pass criteria.
