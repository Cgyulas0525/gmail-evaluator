---
name: gmail-evaluator-chart-standards
description: Standardize dashboard charts and KPI cards in Gmail Evaluator. Use when creating or refactoring stats widgets, trend bars, category/priority/sentiment distributions, or dashboard refresh behavior in App.jsx.
---

# Gmail Evaluator Chart Standards

## Scope

Use this skill for dashboard analytics UI in `frontend/src/App.jsx` backed by `GET /api/emails/stats`.

## Data Source

- Backend: `EmailController::statistics()`
- Frontend state: `stats` object with `total_emails`, `categories`, `priorities`, `sentiments`, `trend`, `accounts`, `recent_urgent`
- Refresh via `fetchStats()`; also runs on 60s background poll with accounts/stats/inbox

## KPI Cards (`.stats-grid`)

- Use `.stat-card` inside `.card`.
- Urgent count: sum of `priorities.urgent + priorities.high`, color `--danger`.
- Active accounts: filter `accounts.status === 'active'`, color `--success`.
- Keep subtitles short in Hungarian.

## Trend Chart (`.bar-chart`)

- 7-day trend from `stats.trend` (`label`, `count`, `date`).
- Bar height relative to max value in period (avoid divide-by-zero).
- Tooltip shows exact count (`N db`).
- Labels use short day names from backend (`ddd`).

## Distribution Blocks

- Category/priority/sentiment breakdowns use existing badge/color semantics.
- Sentiment bars: positive `--success`, neutral `--text-secondary`, negative `--danger`.
- Percent widths based on `stats.total_emails` with fallback `|| 1`.

## Color Semantics (keep stable)

- Priority: urgent/high/medium/low → existing `--priority-*` badges
- Category: billing/work/spam/promotion/personal/security → `--cat-*` or badge classes
- Do not reuse priority colors for unrelated metrics

## Interaction

- Dashboard refresh button calls `fetchStats()`, `fetchAccounts()`, `fetchEmails()` together.
- Do not update charts without updating related KPI numbers from the same `stats` payload.

## Acceptance Checklist

- [ ] Uses `/api/emails/stats` fields consistently
- [ ] Handles empty/zero data gracefully
- [ ] Colors match existing CSS variables
- [ ] Hungarian labels in UI
