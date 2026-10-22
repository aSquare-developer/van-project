# Architecture

Laravel 13, PHP 8.3+, MySQL 8+, Blade, Tailwind CSS 4 and Vite. The application is server rendered and uses session authentication. No public API or public registration is exposed.

## Access

| Role | Dashboard/profile | User CRUD, roles, blocks | Activity log |
| --- | --- | --- | --- |
| Admin | Yes | Yes | Yes |
| Moderator | Yes | No | No |
| User | Yes | No | No |

Moderator is an explicit role ready for future domain permissions. It has no user-administration privilege. User roles are fixed enum values; users cannot create arbitrary roles.

`auth`, `active` and `role:admin` middleware protect routes. UserPolicy checks each management action; FormRequests validate and whitelist input. The active middleware reloads the account and compares the session version to reject blocked, deleted or revoked sessions.

## Administration consistency

All account administration, including initial bootstrap, runs through UserManager. An InnoDB row in `administration_lock` serializes changes inside a transaction. After acquiring it, the service reloads and authorizes the actor, locks the subject and checks the last-active-admin invariant. All writers must use this service. Direct SQL changes bypass application safeguards.

Self-block and self-delete are forbidden. Self-demotion is allowed only when another active admin remains. Blocked admins do not count. Role, password, email and status changes rotate the session version and remember token and remove database sessions and password reset tokens. Remember-me login is not offered.

Password reset uses Laravel's broker with a 60-minute single-use token, active-account restriction and trusted APP_URL. Reset changes the password and revokes sessions inside a user-row transaction. Throttling applies per account and IP. Reset email requests return a generic result.

## Audit

Administrative changes and their audit entries share a transaction. Audit failure rolls back the mutation. Authentication events are logged synchronously. Records contain event names, actor/subject IDs and whitelisted changed field names, without names, email addresses, passwords, hashes, tokens, session payloads or request bodies. IDs survive account deletion. There is no web endpoint to edit or delete audit entries.

The application audit trail is not tamper-proof against database administrators. Set an appropriate log retention policy and protect database backups.

## Verification

See [security.md](security.md) for SEC-001–SEC-007 and [deployment.md](deployment.md) for deployment. CI tests supported PHP versions against MySQL 8.4, including a two-process race test; SQLite local tests skip that one test.
