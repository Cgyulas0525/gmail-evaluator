# Gmail Aggregator & AI Evaluator 🚀

Egy rendkívül modern, konténerizált webalkalmazás, amellyel szabadon megadhatsz több Gmail címet. Az alkalmazás automatikusan (és manuálisan indítható módon is) összeszedi a leveleidet, majd egy fejlett **Gemini AI** motor segítségével kiértékeli azokat (prioritás, kategória, hangulatelemzés, magyar nyelvű AI-összefoglaló és teendő-listák generálásával).

Az alkalmazás teljes egészében Docker alapú, és úgy lett megtervezve, hogy a magasabb egyedi portkiosztásoknak köszönhetően **véletlenül se ütközzön semmilyen meglévő Docker konténereddel vagy szolgáltatásoddal**.

---

## 🛠️ Alkalmazott Technológiák

- **Frontend**: React (Vite-tel felépítve)
- **Backend**: Laravel 13 (a legújabb PHP 8.4-FPM alapú API)
- **Adatbázis**: MySQL 8.0
- **Gyorsítótár és Sorok**: Redis (Alpine)
- **Webszerver és Proxy**: Nginx (Alpine)
- **E-mail Tesztelés**: Mailhog (kimenő levelek megfogásához)

---

## 🌐 Portkiosztás és Elérés (Ütközésmentes)

| Szolgáltatás | Külső Host Port | Belső Port | Leírás | Elérés |
| :--- | :--- | :--- | :--- | :--- |
| **Nginx (Web Proxy)** | `28088` | `80` | A teljes alkalmazás kapuja | [http://localhost:28088](http://localhost:28088) |
| **Vite Dev Server** | `5173` | `5173` | React hot-reload kiszolgáló | Nginx proxy-n keresztül érhető el |
| **MySQL** | `23306` | `3306` | Adatbázis elérés | `localhost:23306` |
| **Redis** | `26379` | `6379` | Gyorsítótár elérés | `localhost:26379` |
| **Mailhog SMTP** | `21026` | `1025` | E-mail küldési teszt port | - |
| **Mailhog Web UI** | `28026` | `8025` | Kimenő teszt e-mailek inboxa | [http://localhost:28026](http://localhost:28026) |

---

## 🚀 Telepítés és Indítás

A projekt gyökerében elhelyezett `Makefile` segítségével az indítás rendkívül egyszerű.

### 1. Előfeltételek
- Telepített [Docker](https://www.docker.com/) és Docker Compose.

### 2. Projekt Indítása
Nyiss egy terminált a projekt gyökerében, és futtasd az alábbi parancsot:
```bash
make up
```
*Ez a parancs a háttérben letölti és elindítja a webszervert, a backendet, a frontendet, az adatbázist, a Redis-t és a Mailhog-ot.*

### 3. AI Kiértékelés aktiválása (Opcionális, de erősen ajánlott!)
Az intelligens AI elemzés eléréséhez nyisd meg a `backend/.env` fájlt, és a `GEMINI_API_KEY` sorba írd be a Gemini API kulcsodat:
```env
GEMINI_API_KEY=a_te_api_kulcsod
```
*Ha nincs megadva API kulcs, az alkalmazás **automatikusan átvált egy kifinomult beépített szabály- és kulcsszó-alapú elemzőre**, így az alkalmazás azonnal, API kulcs nélkül is 100%-ban tesztelhető és működőképes marad!*

---

## ✉️ Gmail Csatlakoztatás Útmutató (App Password)

A Gmail szigorú biztonsági szabályai miatt a rendes Google-jelszavad nem használható külső szkriptekkel. A csatlakozáshoz kövesd az alábbi 5 egyszerű lépést (a webes felületen is megtalálod a részletes leírást):

1. Jelentkezz be a Google fiókodba, és nyisd meg a **Biztonság (Security)** fület.
2. Kapcsold be a **2-lépcsős azonosítást (2-Step Verification)**, ha még nincs bekapcsolva.
3. Kattints a 2-lépcsős azonosítás menüponton belül a lap legalján található **Alkalmazás-jelszavak (App Passwords)** opcióra.
4. Adj meg egy nevet az alkalmazásnak (pl. *Gmail Evaluator*), és kattints a **Létrehozás** gombra.
5. Másold ki a Google által generált sárga mezős, 16-karakteres kódot (pl. `abcd efgh ijkl mnop`), és illeszd be az alkalmazás felületén az új fiók hozzáadása űrlap jelszó mezőjébe!

---

## 💻 Hasznos Parancsok (`Makefile` parancsok)

- `make up` - Konténerek elindítása a háttérben.
- `make down` - Konténerek leállítása.
- `make restart` - Konténerek újraindítása.
- `make status` - Konténerek futási állapotának ellenőrzése.
- `make logs` - Folyamatos docker naplófájlok (logs) megtekintése.
- `make migrate` - Adatbázis migrációk kézi futtatása (a konténeren belül).
- `make fetch` - E-mailek azonnali kézi lekérése és AI kiértékelése parancssorból.
- `make backend-shell` - Belépés a backend PHP konténer termináljába.
- `make frontend-shell` - Belépés a frontend React konténer termináljába.

---

## 🧠 Hogyan működik a Kiértékelés?

Minden egyes letöltött e-mailen az alábbi vizsgálatokat végezzük el:
1. **Prioritás osztályozása**: *Sürgős (Urgent)*, *Magas (High)*, *Közepes (Medium)* vagy *Alacsony (Low)* kategóriába sorolás.
2. **Kategória besorolás**: Automatikus szortírozás: *Pénzügy / Számla*, *Munka*, *Biztonság*, *Promóció*, *Spam*, *Személyes*.
3. **Hangulatelemzés (Sentiment)**: Meghatározza a levél hangvételét (*Pozitív*, *Semleges*, *Negatív*).
4. **Magyar nyelvű összefoglaló**: Egy nagyon tiszta, 1-2 mondatos magyar összefoglalót generál a levél lényegéről.
5. **Teendők (Action Items)**: Kigyűjti a levélből az elvégzendő feladatokat egy pipálható teendőlistába.
