# Gmail Evaluator Skills

Projekt-szintű Cursor skillek a [gmail-evaluator](https://github.com/Cgyulas0525/gmail-evaluator) repóhoz.

## Elérhető skillek

- `gmail-evaluator-design`
  - Fókusz: sötét dashboard UI, sidebar, inbox, detail panel, badge-ek, modalok.
  - Használd: frontend kinézet/megjelenés módosításakor.

- `gmail-evaluator-api-structure`
  - Fókusz: Laravel API + Service réteg + React fetch integráció, IMAP/Gmail, AI, auto-reply.
  - Használd: új backend/frontend funkciók építésekor.

- `gmail-evaluator-chart-standards`
  - Fókusz: dashboard KPI kártyák, 7 napos trend, kategória/prioritás/hangulat bontások.
  - Használd: statisztika/analitika UI fejlesztéskor.

## `.ai` agent promptok

- `.ai/model-agent.txt` — model + migration generálás (Budget2/dinamicHP mintával megegyező)
- `.ai/crud-agent.txt` — Gmail Evaluatorhoz adaptált CRUD/API minta (NEM Inertia)

## Budget2 skill-ekkel való kapcsolat

A Budget2 ERP skill-ek (`erp-budget2-design`, `erp-budget2-crud-structure`, `erp-budget2-chart-standards`) **Inertia + ERP** stackre készültek.

Ez a projekt **Laravel JSON API + React SPA + Docker**, ezért itt a fenti `gmail-evaluator-*` skillek az ekvivalensek.

## Gyors promptok

- Design: „Igazítsd az inbox detail panelt a Gmail Evaluator designhoz (badge-ek, detail-actions, compose modal).”
- API: „Adj hozzá új API végpontot Service réteggel, és kösd be az App.jsx-be.”
- Chart: „Egészítsd ki a dashboardot új KPI-val a `/emails/stats` adatforrásból.”
