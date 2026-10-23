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

## Vision service contract v1

Laravel sends a server-to-server multipart POST to the configured trusted HTTPS endpoint. Headers: `Authorization: Bearer <server token>`, `Accept: application/json`, `Idempotency-Key: <attempt UUID>`. Fields: `protocol_version=1`, `attempt_id=<same UUID>`, `check_key=silicone_holder`, `model_version=<configured immutable version>`, and binary `image` with its verified JPEG/PNG/WebP MIME type. No public photo URL or worker-supplied endpoint/result is trusted. Redirects are disabled.

Return HTTP 200 with JSON (maximum 64 KiB):

```json
{
  "protocol_version": 1,
  "attempt_id": "<same UUID>",
  "check_key": "silicone_holder",
  "model_version": "<same immutable version>",
  "outcome": "present",
  "class": "holder_stocked",
  "confidence": 0.95,
  "boxes": [{"x": 0.1, "y": 0.2, "width": 0.3, "height": 0.4}]
}
```

For `absent`, class must be `holder_empty`; for `uncertain`, class must be null. Confidence is 0–1, with a server-side threshold of 0.8 for this check. Below threshold, both present/absent become inconclusive. The service must use uncertain when the target holder/area cannot be located or assessed; lack of a stocked-object detection alone is not evidence of an empty holder. Configure/train these classes accordingly before real acceptance. Boxes are optional detections represented by a required list (empty is valid), at most 100, using normalized top-left x/y and positive width/height inside image bounds. Unknown response fields are ignored and never persisted. Mismatched attempt/check/model/class, malformed JSON or invalid boxes are infrastructure errors, not missing objects.

An attempt preserves normalized result, class, confidence, boxes, model version, timestamps and sanitized error code. The queue contains only the attempt ID; private bytes and bearer tokens stay out of the payload. No raw provider body is retained. Result/audit/check changes commit together. Only the newest attempt can change an editable shift check, and a processing-token comparison prevents an expired worker from committing after a replacement claim. Closed or under-review shifts are never silently changed by late analysis. The browser shows attempt status and confidence; refreshing retrieves the latest server state. No live camera inference is implemented.

## Inspection completion and review

These states describe checklist verification, not attendance, working hours or payroll. Each assigned worker has an independent shift/checklist. `completed_at` is the time the inspection was closed.

| Action | Allowed source | Evidence / authorization | Target |
| --- | --- | --- | --- |
| Complete inspection | assigned, in_progress, returned_for_check | Active assigned worker; every required item has a valid latest passing analysis and private photo | completed |
| Request review | same editable states | Active assigned worker; every required item has a photo and terminal analysis, at least one is not confirmed; explanation required | awaiting_review |
| Approve with remarks | awaiting_review | Active Admin other than the assigned worker; unchanged request/evidence; recorded explanation | completed_with_remarks |
| Return for checking | awaiting_review | Active Admin other than the assigned worker; recorded instructions | returned_for_check |

Unscanned required items, queued/processing latest attempts (including optional items), empty/no-required checklists and invalid or missing evidence cannot be submitted as completed or for review. Assignment now rejects templates without required items, avoiding unfinishable new checklists. Legacy zero-required assignments need a new correctly configured assignment, not a status override. A finished analysis error is eligible for **manual review only**, never normal completion. Optional unscanned/terminal nonpassing items do not block normal completion. Superseded attempts do not satisfy required evidence and do not block a newer terminal result. After return, every required item that was unconfirmed at that decision needs a new photo and finished analysis before the next submission; already-passed items need not be rescanned.

The workflow rechecks the actor and locks the shift before checking the latest attempts. Photo submissions, applicable terminal analyses and workflow actions increment the shift revision. POSTs require the displayed revision and a UUID scoped to the shift. Identical retries return the original transition; key reuse for a changed actor/action/comment/revision is rejected. Stale pages and competing decisions cannot silently finalize newer evidence. Shift/check locks serialize workflow changes against photo uploads and analysis commits. The database unique action key provides a second guard.

Awaiting-review and closed checklists reject photo uploads. Closed shifts have no edit/reopen workflow. A return is permitted even if an evidence file has disappeared, allowing a new capture; approval without valid photo evidence is forbidden. Admin approval never changes analysis/check results to passed. Each transition stores actor, previous/next state, timestamp, comment, revisions and the exact check/attempt/result snapshot. Worker/Admin pages show paginated decision history and scoped links to those photos. Attempt/decision records survive photo retention; comments are escaped, access-controlled and excluded from general audit metadata. Reviewer accounts with decision history must be blocked rather than deleted.

Workflow endpoints use session authentication, active-account policies, CSRF protection, validated fields and a 30/minute per-account throttle. Terminal state/history/audit updates share one transaction. MySQL CI tests real simultaneous duplicate completion, photo-upload/finalize races and competing administrator decisions; SQLite skips these row-locking tests.

## Verification

See [security.md](security.md) for SEC-001–SEC-007 and [deployment.md](deployment.md) for deployment. CI tests supported PHP versions against MySQL 8.4, including a two-process race test; SQLite local tests skip that one test.
