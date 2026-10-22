# Architecture decisions

## Application
- Laravel, Blade and Tailwind CSS, MySQL.
- Session-based email/password authentication; no public registration.
- Three fixed roles: admin, moderator, user.
- Enforce permissions server-side with middleware and policies.
- Admin-only user creation, role changes and blocking.
- Protect against self-deactivation and removal/demotion of the last active admin.
- Audit privileged changes without logging secrets.

## Security checklist
- SEC-001: Hash passwords with Laravel Hash.
- SEC-002: Rate-limit authentication and reset attempts.
- SEC-003: Check authorization on each protected route/action.
- SEC-004: Block admin self-delete and self-deactivation.
- SEC-005: Preserve at least one active admin under concurrent updates.
- SEC-006: Password reset by email.
- SEC-007: No public registration; only admin creates accounts.

## Implementation status
Planning only. Laravel skeleton, controllers, migrations and tests are not implemented yet.
