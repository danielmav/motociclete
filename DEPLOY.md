# Deploy — staging `motociclete.com.ro/2026/` (cPanel Git™ Version Control)

Mediu de preview pentru clienți, alimentat din repo-ul **privat** `danielmav/motociclete`,
cu baza `dualmotors_motociclete2026`. Repo-ul e clonat **direct în docroot-ul subfolderului**
(`public_html/2026`), deci „Update from Remote" = `git pull` în loc.

Aplicația suportă subfolder nativ: `BASE_PATH` → `Slim::setBasePath` (`src/Bootstrap.php`),
iar template-urile/JS folosesc `{{ base }}` / `window.BASE`. `.env`, `vendor/`, `media/`,
`storage/cache` sunt **gitignored** → `git pull` nu le atinge niciodată.

---

## Setup unic (o singură dată)

### 1. PHP 8.1+
cPanel → **MultiPHP Manager** → domeniul `www.motociclete.com.ro` → PHP **8.1+**.

### 2. Deploy Key (repo privat)
1. cPanel → **SSH Access → Manage SSH Keys** → copiază cheia **publică** a contului
   (sau generează una nouă și autoriz-o).
2. GitHub → repo → **Settings → Deploy keys → Add deploy key** → lipești cheia publică,
   **fără** „Allow write access" (read-only e suficient).

### 3. Clone în cPanel
cPanel → **Git Version Control → Create → Clone a Repository**:
- **Clone URL:** `git@github.com:danielmav/motociclete.git`  *(SSH, nu HTTPS)*
- **Repository Path:** `public_html/2026`

### 4. `.env` pe server
File Manager → `public_html/2026/.env` (activează „Show Hidden Files"). Pleacă de la
`.env.example` și setează valorile de producție:
```
APP_ENV=prod
APP_DEBUG=false
APP_URL=https://www.motociclete.com.ro
BASE_PATH=/2026
TWIG_CACHE=true
DB_LOCAL_HOST=localhost
DB_LOCAL_NAME=dualmotors_motociclete2026
DB_LOCAL_USER=<user_db_2026>
DB_LOCAL_PASS=<parola>
ADMIN_USER=<...>
ADMIN_PASS=<...>
TESTRIDE_URL=https://www.motociclete.com.ro/   # sau URL-ul drivetest
# BIKERSHOP_* / DM_* — opțional; lipsă = degradare grațioasă (fără „Fit My Bike")
```

### 5. Baza de date (dump local → import prod)
Export local `motociclete` (include tabela `settings`), apoi import în
`dualmotors_motociclete2026`:
```powershell
# Export (binarul Laragon are mysqldump cu pdo_mysql)
& "C:/laragon/bin/mysql/mysql-8.../bin/mysqldump.exe" -u root motociclete > motociclete_dump.sql
```
Import: fie prin **phpMyAdmin** (urci `.sql`-ul — nu necesită Remote MySQL), fie remote
dacă IP-ul e whitelisted în cPanel → **Remote MySQL**.

### 6. Imagini (FTP)
Se urcă manual în `public_html/2026/media/` cu numele originale (vezi `database/README.md`):

| Sursă | Țintă |
|-------|-------|
| galerie / culori / detalii Yamaha | `media/yamaha/{motociclete,culori,detalii}/` |
| galerie / culori / detalii CFMOTO | `media/cfmoto/{motociclete,culori,detalii}/` |
| imaginea principală (`cover_image`) | `media/{brand}/cover/` |

### 7. Primul deploy
Git Version Control → repo → tab **Pull or Deploy** → **Update from Remote** →
**Deploy HEAD Commit** (rulează `composer install` din `.cpanel.yml`).

> În `.cpanel.yml` înlocuiește `CPANELUSER` cu userul contului și confirmă calea
> PHP/composer (vezi comentariile din fișier).

---

## Workflow recurent — după fiecare modificare majoră

1. **Local:**
   ```powershell
   git add -A
   git commit -m "descriere"
   git push origin main
   ```
2. **cPanel:** Git Version Control → repo → **Pull or Deploy** →
   **Update from Remote**, apoi **Deploy HEAD Commit**.

`composer install` rulează doar la nevoie (idempotent). `.env` / `media/` / `vendor/`
rămân neatinse. **Nu edita fișiere direct pe server** — altfel `git pull` dă conflict
(se rezolvă cu reset la `origin/main` din cPanel).

---

## Verificare

- `https://www.motociclete.com.ro/2026/` → home corect (CSS/JS/imagini cu prefix `/2026`).
- `…/2026/health` → status app + BikerShop.
- `…/2026/yamaha` + un produs → imagini din `/2026/media/...`.
- `…/2026/.env` și `…/2026/.git/config` → **403 Forbidden**.
- `…/2026/admin` → HTTP Basic; cursul EUR→RON editabil.
- Un URL legacy `*.html` → **301** către canonic.
