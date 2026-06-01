# Catalog — bază de date locală & imagini (Milestone 2)

Catalogul Yamaha + CFMOTO se importă din bazele vechi în baza locală `motociclete`.

## Migrarea datelor

```powershell
& "C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" database/migrate_catalog.php
```

- Aplică `schema.sql` (drop + recreate — idempotent) și copiază **categoriile + produsele
  active** și rândurile de imagini pentru ambele branduri în `motociclete`.
- NU descarcă fișierele imagine (vezi mai jos). Adaugă flag-ul `--download` doar dacă vrei
  descărcare prin HTTP (lentă, limitată de Cloudflare).

Sursa: `dualmotors_motociclete` (Yamaha) + `dualmotors_cfmoto` (CFMOTO), credențiale `DM_*` din `.env`.
Necesită PHP 8.1 (Laragon) — CLI-ul implicit 8.2 nu are `pdo_mysql`.

## Imaginile — copiere 1:1 de pe server

Fișierele imagine NU intră în git (`/media/` e gitignored). Se copiază direct de pe serverul
de producție, păstrând **numele originale**, după acest mapping:

| Brand  | Folder pe server (`www.motociclete.com.ro`) | Folder local |
|--------|----------------------------------------------|--------------|
| Yamaha | `/images/culori/`        | `media/yamaha/culori/` |
| Yamaha | `/images/motociclete/` (galerie) | `media/yamaha/motociclete/` |
| Yamaha | `/images/detalii/`       | `media/yamaha/detalii/` |
| CFMOTO | `/cfmoto/images/culori/` | `media/cfmoto/culori/` |
| CFMOTO | `/cfmoto/images/motociclete/` (galerie) | `media/cfmoto/motociclete/` |
| CFMOTO | `/cfmoto/images/detalii/`| `media/cfmoto/detalii/` |

> Nota: tabela legacy `imagini` (galeria) e servită din folderul `.../motociclete/`, nu `.../imagini/`.

Aplicația servește imaginile din `/media/{brand}/{folder}/{nume-fișier}` (numele e
url-encodat automat, deci spațiile din numele CFMOTO funcționează).

Nu e nevoie de toate fișierele de pe server — doar cele referite de produsele active sunt
folosite (~3.700 rânduri în `product_images`). Dacă lipsește un fișier, pagina afișează
placeholder-ul elegant `.media-ph`.
