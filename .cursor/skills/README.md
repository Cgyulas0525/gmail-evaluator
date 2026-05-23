# Gmail Evaluator Skills

Projekt-szintű Cursor skillek a [gmail-evaluator](https://github.com/Cgyulas0525/gmail-evaluator) repóhoz.

A csapat koordinátor és általános szerepek: `~/.cursor/skills/menteshetes-*` (lásd [[Index]] Obsidianban).

## Elérhető skillek (repo + `~/.cursor/skills/`)

- `gmail-evaluator-design`
  - Fókusz: sötét dashboard UI, sidebar, inbox, detail panel, badge-ek, modalok.
  - Használd: frontend kinézet/megjelenés módosításakor.

- `gmail-evaluator-api-structure`
  - Fókusz: Laravel API + Service réteg + React fetch integráció, IMAP/Gmail, AI, auto-reply.
  - Használd: új backend/frontend funkciók építésekor.

- `gmail-evaluator-chart-standards`
  - Fókusz: dashboard KPI kártyák, 7 napos trend, kategória/prioritás/hangulat bontások.
  - Használd: statisztika/analitika UI fejlesztéskor.

## MentesHetes csapat integráció

| Általános feladat | Skill |
|-------------------|-------|
| Koordinált feature | `menteshetes-team` |
| Docker / compose | `menteshetes-devops` |
| Migráció | `menteshetes-database` |
| PM / BA / review | `menteshetes-product-manager`, `menteshetes-ba`, `menteshetes-devils-advocate` |
| Feature zárás — skill audit | `menteshetes-skill-keeper` |
| Feladat napló Obsidian | `menteshetes-obsidian-docs` |

Projekt összehasonlítás: `~/.cursor/skills/menteshetes-team/projects.md`

## `.ai` agent promptok

- `.ai/model-agent.txt` — model + migration generálás
- `.ai/crud-agent.txt` — Gmail Evaluatorhoz adaptált CRUD/API minta (NEM Inertia)

## Stack különbség budget2-től

A budget2/dinamicHP skill-ek **Inertia + Tailwind** stackre készültek.

Ez a projekt **Laravel JSON API + React SPA + Docker** (port **28088**), ezért a `gmail-evaluator-*` skillek az ekvivalensek.

## Gyors promptok

- Design: „Olvasd a gmail-evaluator-design skillt — inbox detail panel badge-ek, compose modal.”
- API: „Olvasd a gmail-evaluator-api-structure skillt — új végpont Service réteggel + App.jsx.”
- Chart: „Olvasd a gmail-evaluator-chart-standards skillt — új KPI a `/emails/stats`-ból.”
- Csapat: „Olvasd a menteshetes-team skillt — új auto-reply szabály gmail-evaluatorban.”
- Skill audit: „Olvasd a menteshetes-skill-keeper skillt — kell-e frissíteni a skilleket?”
