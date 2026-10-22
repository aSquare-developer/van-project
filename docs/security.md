# Security acceptance

| Requirement | Implementation | Automated verification |
| --- | --- | --- |
| SEC-001 — Password hashing | Laravel hashed cast; shared 12-character mixed-case, digit and symbol policy with a 72-byte bcrypt limit; hidden CLI prompt | UserManagementTest, CreateAdminTest, PasswordResetTest |
| SEC-002 — Throttling | Account-key and IP rate limits for login and password reset; generic reset response | AuthenticationTest, PasswordResetTest |
| SEC-003 — Authorization | Auth + active session version + role middleware, UserPolicy, reauthorization inside UserManager transaction | AuthorizationTest, UserManagementTest, SecurityAuditTest |
| SEC-004 — Self-protection | Policy and service prohibit self-delete/self-block | UserManagementTest |
| SEC-005 — Last active admin | Transaction plus singleton row lock; active-admin count after lock; bootstrap uses same lock | UserManagementTest; AdminInvariantTest launches two simultaneous processes on MySQL |
| SEC-006 — Email reset | Laravel broker, active-only account, trusted APP_URL, expiry, token consumed, all sessions revoked | PasswordResetTest, SecurityAuditTest |
| SEC-007 — No registration | No register routes; only admin web requests create accounts; initial account created interactively on server | AuthenticationTest, UserManagementTest, CreateAdminTest |

CSRF is verified with the framework's actual request-forgery middleware enabled, including login, logout, reset and all management writes (CsrfTest). Blade escapes user values. Production responses set a strict content security policy, anti-framing and MIME-sniffing headers; authenticated pages use no-store. Production cookies are secure, HttpOnly and SameSite=Lax. Configure TLS and APP_DEBUG=false on the server.

Audit tests verify event coverage, secrecy, persistence after deletion and transactional rollback when audit storage fails. Password resets and account changes remove old session records and advance a session version, so replaying an old cookie also fails.

## Remaining deployment validation

SMTP delivery must be tested with the actual provider before release. The automated suite fakes email delivery and checks the reset notification, token flow and destination URL. Configure monitoring and backup restoration on the chosen server. No server configuration or real credentials are included in this repository.
