---
name: gmail-evaluator-design
description: Apply the Gmail Evaluator dark dashboard UI style. Use when updating frontend layout, sidebar, cards, inbox list, email detail panel, badges, forms, modals, or CSS in frontend/src/App.jsx and frontend/src/index.css.
---

# Gmail Evaluator Design

## Scope

Use this skill when implementing or reviewing UI for the Gmail Evaluator app (`frontend/src/App.jsx`, `frontend/src/index.css`).

## Visual Baseline

- Dark dashboard theme using CSS variables in `:root` (not Tailwind brand colors).
- Background: `--bg-main`, cards: `--bg-card`, sidebar: `--bg-sidebar`.
- Accent colors: `--primary` (cyan), `--accent` (purple), semantic `--success`, `--warning`, `--danger`.
- Typography: `--font-body` (Inter), `--font-title` (Outfit).
- Glassmorphism cards with `--shadow-main`, `--radius-lg/md/sm`.

## Layout Pattern

1. Left sidebar (`.sidebar`) with logo + nav tabs: dashboard, inbox, accounts.
2. Main content (`.main-content`) with top sync header (`.app-header`).
3. Page header row: title + optional refresh/action button (`.page-header`).
4. Content uses `.card`, `.dashboard-grid`, `.stats-grid` as appropriate.

## Inbox Pattern

- Three-column inbox layout: filters, list (`.inbox-item`), detail panel (`.inbox-detail`).
- Active list item: `.inbox-item.active`.
- Badges for priority/sentiment/category using existing `badge-*` classes.
- Detail actions in `.detail-actions` (Válasz, Továbbítás, Törlés).

## Forms and Modals

- Inputs: `.form-group`, `.form-label`, `.form-input`.
- Primary action: `.btn.btn-primary`; secondary: `.btn.btn-secondary`; destructive: `.btn.btn-danger`.
- Compose modal: `.compose-overlay`, `.compose-modal`, `.compose-actions`.

## Do Not

- Introduce Inertia, Tailwind config, or Budget2 green ERP chrome.
- Hardcode colors when an existing CSS variable exists.
- Break mobile readability in inbox/detail panels without checking overflow.

## Acceptance Checklist

- [ ] Uses existing CSS variables and component classes
- [ ] Hungarian labels for user-facing text
- [ ] Loading and error states match existing patterns (`spinner`, `showNotification`)
