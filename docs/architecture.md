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

## Vision providers and rollout

The existing queue now routes through `VisionClient` by the provider snapshotted on each attempt. `custom` retains the v1 contract below; `openai` calls the fixed `https://api.openai.com/v1/responses` endpoint directly, without another server or new PHP dependencies. Unknown providers fail closed. Existing records migrate to `custom` and preserve their earlier behavior.

New OpenAI attempts snapshot the configured model, `silicone-holder-v1` prompt and automatic-decision policy. This prompt supports only the registered silicone holder check; adding an arbitrary template title does not add a new recognition capability. Each check needs a versioned criterion and acceptance examples before support is added. Changing model/provider does not reinterpret pending attempts. Old prompt versions must stay implemented or fail with a configuration error, never silently change meaning.

OpenAI receives only the private current photograph and the reference already assigned to the check, labelled separately as base64 image inputs. A reference is mandatory for this first criterion: missing/purged files fail before sending anything. Template instructions are not sent as model instructions; the server prompt tells the model to treat text in both images as untrusted evidence. This is an instruction to the model, not a guarantee against adversarial images. The reference identifies the intended holder and never establishes its current stock. The criterion requires a visible holder containing at least one silicone/sealant cartridge; demonstrably empty is absent; hidden, unrelated, blurred or ambiguous is uncertain. The model cannot verify capture location, time, authenticity or whether a worker reused an old photo.

Responses use a strict JSON schema with visibility, outcome, confidence and short summary. The adapter validates types, envelope/model/completion state and size independently, rejects refusals or incomplete output, and forces nonvisible targets to uncertain. Classes are mapped server-side; no bounding boxes are fabricated. Only normalized observation/summary are retained, never raw provider envelopes, image data URLs, tokens or exception text. Summaries are untrusted text and HTML-escaped in the browser. Requests have `store=false`, no tools, disabled redirects, 5-second connect/30-second total timeouts and a 64 KiB response cap. `store=false` does not by itself mean zero provider retention; review the provider data controls before activating private-photo transfer.

**Pilot default:** `VISION_OPENAI_AUTOMATIC_DECISIONS=false`. Observations and explanations are recorded, but checks remain inconclusive. Model-reported confidence is a self-assessment, not measured accuracy. After real-photo validation, explicitly enabling automatic decisions permits present/absent to pass/fail only for new, opted-in attempts at the existing threshold. Disabling the flag also suppresses automatic decisions for queued OpenAI attempts as an operational kill switch; it does not undo previous results. Enabling it never upgrades an earlier advisory attempt. Latest-attempt and closed-shift protections are shared with the custom provider.

Provider reference: [OpenAI vision inputs](https://developers.openai.com/api/docs/guides/images-vision?api-mode=responses) and [Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=responses).

## Vision service contract v1 (custom provider)

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

## Verification

See [security.md](security.md) for SEC-001–SEC-007 and [deployment.md](deployment.md) for deployment. CI tests supported PHP versions against MySQL 8.4, including a two-process race test; SQLite local tests skip that one test.
