---
name: gmail-evaluator-api-structure
description: Build Gmail Evaluator features with consistent Laravel API architecture. Use when adding routes, controllers, services, migrations, IMAP/Gmail sync, AI evaluation, auto-reply, compose, attachments, or React fetch integration.
---

# Gmail Evaluator API Structure

## Scope

Use this skill for backend and API-facing frontend work in the Gmail Evaluator Docker app.

## Stack

- Laravel API: `backend/routes/api.php`
- Controllers: thin orchestration in `app/Http/Controllers/*`
- Services: business logic in `app/Services/*`
- Models: `app/Models/*` with casts/fillable/SoftDeletes where needed
- Scheduler: `backend/routes/console.php` + docker `scheduler` service
- Frontend consumes JSON via `VITE_API_URL` (default `http://localhost:28088/api`)

## Required Flow for New Features

1. Migration/model changes (if persistent data needed)
2. Service method(s) with explicit exceptions for user-facing errors
3. Controller endpoint returning JsonResponse
4. Route registration in `routes/api.php`
5. Frontend fetch handler + UI in `App.jsx`
6. Update `.env.example` if new config keys are required (never commit secrets)

## Existing Service Boundaries

| Service | Responsibility |
|---------|----------------|
| EmailFetcherService | IMAP via `connect(GmailAccount)`, `$account->imapSelectCommand()` (not hardcoded INBOX), sync, MIME parsing |
| EmailEvaluatorService | Gemini AI + rule-based fallback classification |
| EmailProcessingService | Evaluate + optional auto-reply pipeline |
| EmailAutoReplyService | Automatic billing/work replies via account SMTP (`smtpDsn()`); skips info/no-reply senders |
| EmailComposeService | Manual reply/forward via account SMTP (`smtpDsn()`) |
| EmailAttachmentService | BODYSTRUCTURE parsing + on-demand IMAP download |

## API Response Conventions

- Success mutations: `{ success: true, message: "..." }`
- Errors: `{ success: false, message: "..." }` with proper HTTP code
- Lists: Laravel paginator JSON (`data`, `current_page`, `last_page`, ...)
- Do not leak stack traces or credentials in production responses

## Email-Specific Rules

- Deduplicate by `message_id`; respect soft deletes via `Email::withTrashed()`
- Store `imap_uid` and `attachments` JSON on sync for attachment download
- Auto-reply categories from `config/auto_reply.php` / `.env`
- Auto-reply skip: `EmailAutoReplyService::isNonReplyableSender()` — ne válaszoljon `info@…` és no-reply feladókra (`noreply`, `no-reply`, `donotreply`); `auto_reply_status: skipped`, reason: `non_replyable_sender`
- IMAP literal reads must use byte-accurate fread (not line-based parsing for attachments)

## Account mail settings (`gmail_accounts`)

- `provider`: `gmail` (defaults via `GmailAccount::gmailDefaults()`) or `custom` (user-supplied IMAP/SMTP)
- Per-account fields: `imap_host`, `imap_port`, `imap_encryption`, `smtp_host`, `smtp_port`, `smtp_encryption`
- `imap_username` nullable — cPanel/webmail rövid login (pl. `menteshe`) ha ≠ teljes `email`
- `imap_mailbox` default `INBOX`; cPanel maildir almappa pl. `INBOX.info@menteshetes_hu` (domain `.` → `_`)
- Model helpers: `settingsFromInput()`, `authUsername()`, `imapStreamHost()`, `imapPort()`, `imapMailbox()`, `imapSelectCommand()`, `cpanelMailboxFromEmail()`, `smtpDsn()`
- IMAP LOGIN + SMTP auth: `authUsername()` — `email` marad megjelenítésre / From címre
- Sync/attachment: `$account->imapSelectCommand()` — soha ne hardcode `SELECT INBOX`
- All IMAP services call `connect(GmailAccount $account)` — never hardcode `imap.gmail.com`
- `POST /api/accounts`: validate `provider`, `imap_host`/`smtp_host` when `provider=custom`; optional `imap_username`, `imap_mailbox`
- Connection test: `testConnection(email, password, mailSettings)` új fióknál; `testAccountConnection($account)` meglévőnél
- Legacy rows: migration defaults preserve Gmail behavior

## Testing Locally

- `make up` / `docker compose up`
- `make fetch` or wait for scheduler (every minute)
- API via `http://localhost:28088/api/...`

## Acceptance Checklist

- [ ] Route added and named consistently
- [ ] Controller stays thin; logic in Service
- [ ] Frontend wired with Hungarian messages
- [ ] `.env.example` updated if config added
- [ ] No secrets committed
