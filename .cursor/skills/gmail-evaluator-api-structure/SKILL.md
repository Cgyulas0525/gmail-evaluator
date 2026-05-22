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
| EmailFetcherService | IMAP connect, sync INBOX, MIME parsing, metadata |
| EmailEvaluatorService | Gemini AI + rule-based fallback classification |
| EmailProcessingService | Evaluate + optional auto-reply pipeline |
| EmailAutoReplyService | Automatic billing/work replies via Gmail SMTP |
| EmailComposeService | Manual reply/forward via Gmail SMTP |
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
- IMAP literal reads must use byte-accurate fread (not line-based parsing for attachments)

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
