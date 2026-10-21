# CINÉLOG - Starter Kit Bagian 1 (Docker, Data, Database)

Tugas Besar Web Semantik + MSBD. Bagian 1 mencakup: infrastruktur multi-database (Docker),
pipeline data film TMDB (ambil -> bersihkan -> impor), dan skema MariaDB lengkap dengan
trigger, function, procedure, dan view.

Semua perintah dijalankan dari **folder root proyek** (yang berisi `docker-compose.yml`).
Contoh memakai PowerShell (Windows) dan bash (Linux/macOS); bagian yang berbeda ditandai.

## 0. Prasyarat

| Alat | Cek dengan | Catatan |
|---|---|---|
| Docker Desktop (Windows memakai WSL2) | `docker --version` dan `docker compose version` | Docker Desktop harus **sedang berjalan** |
| Python 3.11+ | `python --version` (Windows) / `python3 --version` | |
| Git | `git --version` | |
| Akun TMDB + API key | https://www.themoviedb.org/settings/api | Isi "pengguna: pendidikan, non-komersial" |

PHP/Composer belum diperlukan di Bagian 1.

## 1. Jalankan database

```
cp .env.example .env            # Windows PowerShell: copy .env.example .env
# edit .env: ganti semua password
docker compose up -d --build
docker compose ps               # tunggu mariadb berstatus "healthy"
```

Build Fuseki pertama kali mengunduh Apache Jena (+- ratusan MB), butuh internet dan beberapa menit.

Cek tiap layanan:

| Layanan | Cara cek | Hasil yang diharapkan |
|---|---|---|
| MariaDB | `docker compose exec mariadb mariadb -ucinelog -p cinelog -e "SHOW TABLES;"` (masukkan password dari `.env`) | 9 tabel + 2 view |
| Fuseki | buka http://localhost:3030 | halaman Fuseki muncul; dataset `/cinelog` terdaftar |
| MongoDB | `docker compose exec mongo mongosh -u cinelog -p <password> --authenticationDatabase admin --eval "db.getSiblingDB('cinelog_chat').getCollectionNames()"` | `chat_messages`, `chat_sessions` |
| phpMyAdmin (opsional) | `docker compose --profile tools up -d` lalu http://localhost:8081 | login dengan user `cinelog` |

Skrip `database/sql/*.sql` dan `docker/mongo/init/*.js` hanya dijalankan otomatis **saat volume masih kosong**.
Jika Anda mengubah file SQL dan ingin menjalankannya ulang dari nol (MENGHAPUS SEMUA DATA):
`docker compose down -v` lalu `docker compose up -d --build`.

## 2. Siapkan Python

```
python -m venv pipeline/.venv
pipeline/.venv/Scripts/Activate.ps1      # Windows PowerShell
source pipeline/.venv/bin/activate       # Linux/macOS
pip install -r pipeline/requirements.txt
cp pipeline/.env.example pipeline/.env   # Windows: copy pipeline\.env.example pipeline\.env
```

Edit `pipeline/.env`: isi `TMDB_READ_TOKEN` (API Read Access Token dari halaman API TMDB) dan
samakan `DB_PASSWORD` dengan password di `.env` root. `DB_PORT` harus sama dengan `DB_PORT_HOST` (default 3307).

## 3. Uji dulu tanpa API key (opsional tapi disarankan)

Server TMDB **tiruan** (data palsu) untuk memastikan lingkungan Anda benar:

```
# terminal 1
python pipeline/tests/mock_tmdb_server.py --port 8099
# terminal 2 (PowerShell)
$env:TMDB_BASE_URL="http://127.0.0.1:8099/3"; $env:TMDB_READ_TOKEN="dummy"
python pipeline/01_fetch_tmdb.py --target 100
python pipeline/02_clean.py --max 100
```

Setelah selesai, **hapus data tiruan** sebelum mengambil data asli:
`data/raw/*.json` dan `data/clean/*.csv`, dan tutup terminal agar variabel `TMDB_BASE_URL` hilang.

## 4. Ambil data asli dari TMDB

```
python pipeline/01_fetch_tmdb.py --sample 20      # uji 20 film dulu
```

Periksa satu file di `data/raw/movie_<id>.json`. Pastikan ada kunci `credits`, `external_ids`, `genres`, `_id`.
Skrip ini **belum pernah diuji ke API TMDB asli** (hanya ke server tiruan yang meniru dokumentasi);
bila ada kunci yang berbeda, kirim contoh JSON-nya agar kode disesuaikan.

Jika 20 film sudah benar, ambil data penuh (sekitar 700 kandidat, +- 1400 request, belasan menit):

```
python pipeline/01_fetch_tmdb.py
python pipeline/02_clean.py
```

`02_clean.py` mencetak laporan kualitas dan menyimpan `data/clean/report.json`. Pastikan **film_akhir** 300-500.
Jika kurang dari 300: turunkan `--min-votes` (mis. `--min-votes 3`) atau naikkan `--buffer` (mis. `--buffer 2`) di langkah 1,
lalu jalankan ulang (film yang sudah diunduh tidak diunduh lagi).

Menjalankan ulang aman: tidak ada duplikasi. File mentah yang sudah ada dilewati; gunakan `--refresh` untuk paksa unduh ulang.

## 5. Impor ke database

```
python pipeline/03_import_mysql.py --dry-run     # uji, lalu dibatalkan
python pipeline/03_import_mysql.py               # simpan
```

Idempoten: id internal film tidak berubah saat impor ulang, review yang sudah ada tidak hilang,
dan kolom `reviews_count`/`avg_rating` tidak ditimpa.

## 6. Verifikasi

```sql
SELECT COUNT(*) FROM movies;                       -- 300-500
SELECT COUNT(*) FROM genres;                       -- belasan (genre film TMDB)
SELECT COUNT(*) FROM people;                       -- ribuan
SELECT title, genres, directors FROM v_movie_summary ORDER BY release_date LIMIT 5;
SELECT MIN(release_date), MAX(release_date) FROM movies;   -- dalam 2026-01-01 .. 2026-09-30
```

Uji trigger, function, procedure, dan constraint (membersihkan datanya sendiri di akhir):

```
# bash
docker compose exec -T mariadb mariadb -ucinelog -p<PASSWORD> cinelog < database/tests/test_routines.sql
# PowerShell
Get-Content database/tests/test_routines.sql -Raw | docker compose exec -T mariadb mariadb -ucinelog -p<PASSWORD> cinelog
```

Teks "harapan ..." di kolom `uji` menyebutkan hasil yang benar; bandingkan dengan angka di sebelahnya.
Simpan keluarannya (tangkapan layar) sebagai bahan tabel uji validasi di paper.

## Komponen database (bahan MSBD)

| Komponen | Di mana |
|---|---|
| Foreign key + `ON DELETE CASCADE` | `01_schema.sql` |
| Constraint CHECK (rating 1-10, runtime > 0) | `01_schema.sql` |
| Constraint UNIQUE (1 review per user per film) | `01_schema.sql` |
| Index biasa, komposit, dan FULLTEXT | `01_schema.sql` |
| Trigger (4): insert, update, delete review; hapus user | `02_routines.sql` |
| Stored procedure (2): `sp_refresh_movie_rating`, `sp_upsert_review` (dengan transaksi + rollback) | `02_routines.sql` |
| Function: `fn_weighted_rating` | `02_routines.sql` |
| View (2): `v_movie_summary`, `v_top_movies` | `02_routines.sql` |
| Tabel audit yang diisi trigger: `review_audit` | `01_schema.sql` |

Pelajaran penting (bagus untuk ditanyakan dosen): aksi foreign key seperti `ON DELETE CASCADE`
**tidak mengaktifkan trigger**. Karena itu ada trigger `trg_users_bd` yang menghapus review user secara
eksplisit sebelum user dihapus; tanpa itu, rata-rata rating film menjadi basi.

## Masalah umum

| Gejala | Penyebab / solusi |
|---|---|
| `port is already allocated` | Port 3307/3030/27017/8081 dipakai program lain. Ubah di `.env` (DB_PORT_HOST) atau `docker-compose.yml`. |
| `docker: error during connect` | Docker Desktop belum berjalan. |
| Build Fuseki gagal `curl: (22) ... 404` | Versi Jena terhapus dari server. Ubah `FUSEKI_VERSION` di `.env` ke versi yang ada di https://jena.apache.org/download/ |
| Halaman Fuseki tidak muncul / UI tidak ada | Kirim keluaran `docker compose logs fuseki`. Endpoint SPARQL tetap bisa dipakai tanpa UI. |
| Tabel kosong setelah `up` | Volume lama sudah ada sehingga skrip SQL dilewati. `docker compose down -v` lalu `up` lagi. |
| `TMDB menolak kredensial (401)` | Token salah. Pakai "API Read Access Token" (panjang, berawalan `eyJ`) di `TMDB_READ_TOKEN`. |
| `Access denied for user 'cinelog'` saat impor | `DB_PASSWORD` di `pipeline/.env` tidak sama dengan `.env` root. Jika password sudah diubah setelah volume dibuat: `docker compose down -v`. |
| `Can't connect to MySQL server on '127.0.0.1'` | Container belum "healthy", atau `DB_PORT` salah. |
| `film_akhir` < 300 | Lihat bagian 4. |

## Aturan TMDB yang harus dipatuhi

- Tampilkan logo TMDB dan pernyataan: *"This product uses the TMDB API but is not endorsed or certified by TMDB."*
  di footer aplikasi (dikerjakan di Bagian 2).
- Penggunaan non-komersial (tugas kuliah) gratis dengan atribusi. Baca syarat resmi di https://www.themoviedb.org/api-terms-of-use
  karena ada pembatasan lama penyimpanan data; setiap file mentah menyimpan `_fetched_at`.
- Tampilkan poster lewat URL TMDB (`https://image.tmdb.org/t/p/w500` + `poster_path`), jangan menyalin gambar ke server sendiri.

## Struktur

```
docker-compose.yml        MariaDB + Fuseki + MongoDB (+ phpMyAdmin, + app untuk Bagian 2)
.env.example              password dan port (salin jadi .env)
docker/                   Dockerfile Fuseki, init MongoDB, Dockerfile PHP (Bagian 2)
database/sql/             01_schema.sql, 02_routines.sql  (otomatis dijalankan Docker)
database/tests/           test_routines.sql
pipeline/                 01_fetch_tmdb.py, 02_clean.py, 03_import_mysql.py, common.py
pipeline/tests/           mock_tmdb_server.py (data palsu untuk uji)
data/                     raw/ clean/ logs/  (tidak di-commit)
```
