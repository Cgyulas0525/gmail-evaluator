---
name: gmail-evaluator-design
description: Apply the Gmail Evaluator Budget2-style UI (cream background, green sidebar/topbar, white cards). Use when updating frontend layout, sidebar, cards, inbox list, email detail panel, badges, forms, modals, or CSS in frontend/src/App.jsx and frontend/src/index.css.
---

# Gmail Evaluator Design

## Scope

Use this skill when implementing or reviewing UI for the Gmail Evaluator app (`frontend/src/App.jsx`, `frontend/src/index.css`).

## Visual Baseline (Budget2 / dinamicHP aligned)

- **Background:** cream `#fdfaf3` (`--cream`, `--bg-main`)
- **Chrome:** green sidebar + topbar `--brand-800` `#2d4a2b`, active nav `--brand-600` `#4a7c3f`
- **Cards:** warm paper `--bg-card` (`#f8f1e4`), nested rows `--bg-surface` (`#f0e6d2`) — avoid pure white
- **KPI tiles:** colored blocks (`.kpi-card--green`, `--red`, `--blue`) with white text
- **Charts:** dark panels (`.panel-dark`, header `.panel-dark-header` on `--brand-700`)
- **Typography:** Figtree (`--font-body`, `--font-title`)
- **Primary actions:** green `.btn.btn-primary` (`--brand-600`)

## Layout Pattern

1. `.app-container` — flex shell
2. `.sidebar` — fixed green nav (logo, tabs, footer status)
3. `.main-shell` — offset by sidebar width
4. `.topbar` — green header with page title + sync info
5. `.main-content` — cream scroll area with alerts and tab content
6. Optional `.page-header` — subtitle + action button (title lives in topbar)

## Inbox Pattern

- `.inbox-layout` — list + detail
- `.inbox-item` / `.inbox-item.active` — white cards, green active state
- `.inbox-detail` — white card panel
- Badges: `badge-priority-*`, `badge-sentiment-*`, `badge-category`
- Detail actions: `.detail-actions` (Válasz, Továbbítás, Törlés)

## Forms and Modals

- Inputs: `.form-group`, `.form-label`, `.form-input` (white, gray border, green focus ring)
- Buttons: `.btn-primary` (green), `.btn-secondary` (white), `.btn-danger` (red soft)
- Compose: `.compose-overlay`, `.compose-modal`, `.compose-actions`

## Accounts tab (E-mail Fiókok)

- Provider `<select>`: Gmail vs Egyéni IMAP — toggles conditional IMAP/SMTP fields (`.form-group` grid for port/encryption)
- Reuse `GMAIL_MAIL_DEFAULTS` / `CUSTOM_MAIL_DEFAULTS` constants; `handleProviderChange()` resets mail fields
- Account list shows provider badge: „Gmail” | „Egyéni IMAP”

## Do Not

- Revert to dark glassmorphism / cyan-purple accent theme
- Introduce Inertia or Tailwind config unless the project adopts them
- Hardcode colors when an existing CSS variable exists
- Break mobile readability in inbox/detail panels without checking overflow

## Acceptance Checklist

- [ ] Uses existing CSS variables and component classes
- [ ] Hungarian labels for user-facing text
- [ ] Loading and error states match existing patterns (`spinner`, `showNotification`)
