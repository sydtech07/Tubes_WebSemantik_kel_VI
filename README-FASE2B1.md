# CINÉLOG - Fase 2B-1: Pasang Laravel, Hubungkan Data, Push ke GitHub

Hasil akhir fase ini:
1. Laravel terpasang di folder `web\` (dijalankan di Docker, **tidak perlu memasang PHP/Composer di Windows**).
2. Laravel terhubung ke MariaDB (lewat Eloquent) **dan** Fuseki (lewat SPARQL). Ada halaman katalog, halaman film, dan halaman `/status` sebagai bukti koneksi.
3. Seluruh proyek ada di GitHub (repo privat).
4. Teman satu kelompok bisa menjalankan proyek yang sama dengan mengikuti `docs\PANDUAN-TEMAN.md`.

Semua perintah dijalankan di **PowerShell**, dari **folder root proyek** (folder yang berisi `docker-compose.yml`).
Setiap langkah punya bagian **"Harus terlihat"**. Jika hasilnya berbeda, berhenti dan lihat tabel "Masalah umum" di bagian bawah.

> **Apa yang sudah dan belum kuuji.** Sudah: sintaks semua file PHP (`php -l`) dan skrip `configure_laravel_env.py`.
> **Belum:** instalasi Laravel, tampilan Blade, dan koneksi sebenarnya ke MariaDB/Fuseki. Sandbox tempat aku bekerja
> tidak bisa mengunduh Laravel (Packagist diblokir) dan tidak punya Docker yang berjalan. Jadi langkah 2 sampai 5 baru benar-benar diuji
> saat kamu menjalankannya. Kalau ada yang gagal, kirim pesan error persis seperti tampil di layar.

---

## Langkah 0 - Cek kondisi awal

1. Buka **Docker Desktop** dan tunggu sampai statusnya *running*.
2. Di PowerShell:
   ```powershell
   cd C:\Users\LENOVO\Downloads\cinelog-part1\cinelog-part1
   docker compose ps
   ```
   **Harus terlihat:** `cinelog-mariadb` (healthy), `cinelog-fuseki` (Up), `cinelog-mongo` (Up).
   Jika tidak, jalankan `docker compose up -d` lalu cek lagi.
3. Pastikan folder `web` belum ada atau masih kosong:
   ```powershell
   Test-Path web
   ```
   - `False`: aman, lanjut.
   - `True`: lihat isinya dengan `Get-ChildItem web`. Jika berisi sesuatu (misalnya hasil percobaan clone sebelumnya), pindahkan dulu:
     `Rename-Item web web-lama`.

## Langkah 1 - Ekstrak paket ini ke folder root proyek

Ekstrak `cinelog-fase2b1.zip` **ke folder root proyek** (pilih *merge* untuk folder, *replace* untuk `.gitignore`).
Setelah itu di root ada: `web-overlay\`, `scripts\`, `docs\PANDUAN-TEMAN.md`, `.gitignore`, `.gitattributes`, dan file ini.

## Langkah 2 - Pasang Laravel

**2a. Bangun image PHP** (hanya sekali; 5 sampai 10 menit karena mengompilasi ekstensi MongoDB):
```powershell
docker compose --profile app build app
```
**Harus terlihat:** berakhir dengan `Built` / `Image ... Built` tanpa tulisan merah `ERROR`.

**2b. Buat proyek Laravel di dalam `web\`:**
```powershell
docker compose --profile app run --rm app composer create-project laravel/laravel .
```
Ini mengunduh Laravel dan ratusan paket, jadi butuh internet dan beberapa menit. Peringatan kuning tentang "running as root" boleh diabaikan.
Di akhir, Laravel menjalankan migrasi ke file SQLite bawaan. Itu normal dan tidak menyentuh MariaDB. File-nya kita hapus di langkah 3.

**2c. Cek hasilnya:**
```powershell
Test-Path web\artisan
docker compose --profile app run --rm app php artisan --version
docker compose --profile app run --rm app php -m | Select-String mongodb
```
**Harus terlihat:** `True`, lalu `Laravel Framework <nomor versi>` (versi terbaru yang terpasang; catat nomornya), lalu `mongodb`.

## Langkah 3 - Hubungkan data kita ke Laravel

**3a. Salin file Cinélog ke dalam Laravel** (model, klien SPARQL, halaman, rute):
```powershell
robocopy web-overlay web /E
```
Robocopy memakai kode keluar 0 sampai 7 untuk "sukses", jadi tulisan "error level 1" atau "2" itu normal.
**Harus terlihat:** tabel ringkasan dengan `Files : 17 17 0 0 0 0` (17 file tersalin, tidak ada yang gagal).
File ini **menimpa** dua file bawaan Laravel: `routes\web.php` dan `app\Models\User.php`. Memang disengaja.

Setelah itu hapus folder sumbernya agar tidak membingungkan:
```powershell
Remove-Item -Recurse -Force web-overlay
```

**3b. Matikan migrasi bawaan Laravel.** Tabel kita dibuat oleh `database\sql\*.sql`, bukan oleh migration. Migration bawaan
(tabel `users`, `cache`, `jobs`) hanya akan bentrok dan berbahaya:
```powershell
Remove-Item web\database\migrations\*.php
Remove-Item web\database\database.sqlite -ErrorAction SilentlyContinue
```
> **Aturan emas kelompok:** jangan pernah menjalankan `php artisan migrate` atau `migrate:fresh` pada proyek ini.
> `migrate:fresh` **menghapus semua tabel** (termasuk 500 film).

**3c. Isi pengaturan koneksi otomatis:**
```powershell
python scripts\configure_laravel_env.py
```
**Harus terlihat:** `web/.env diperbarui (13 pengaturan)` dan daftar pengaturan; password tampil sebagai `********`.
Skrip ini membaca `.env` di root, lalu mengisi `web\.env` dengan `DB_HOST=mariadb` dan `DB_PORT=3306`
(alamat dari **dalam** Docker; port 3307 hanya berlaku dari Windows), `FUSEKI_URL=http://fuseki:3030/cinelog`, dan
`SESSION_DRIVER/CACHE_STORE=file`.

**3d. Bersihkan cache konfigurasi:**
```powershell
docker compose --profile app run --rm app php artisan config:clear
```

## Langkah 4 - Jalankan dan buktikan

```powershell
docker compose --profile app up -d app
docker compose logs app --tail 10
```
**Harus terlihat** di log: `Server running on [http://0.0.0.0:8000]`.

Lalu buka di browser:

| Alamat | Harus terlihat |
|---|---|
| http://localhost:8000/status | Dua kotak **TERHUBUNG**. MariaDB: film 500, genre 19, orang 7807, trigger 4, routine 3, view 2. Fuseki: film di RDF 500, triple sekitar 100 ribu. |
| http://localhost:8000/ | Grid poster film (24 per halaman), kolom pencarian, dan nomor halaman di bawah. |
| Klik salah satu poster | Halaman film: judul, genre, sinopsis, sutradara, pemeran. Alamat berbentuk `/film/nama-film`. |
| http://localhost:8000/?q=a | Hasil pencarian judul yang mengandung huruf "a". |

Angka trigger/routine/view mengikuti `02_routines.sql` (4 trigger, 2 prosedur + 1 fungsi, 2 view). Jika angkamu berbeda sedikit, kirim tangkapan layarnya.
Poster dimuat langsung dari server gambar TMDB, jadi butuh internet.

**Screenshot halaman `/status` itu penting.** Simpan untuk paper dan presentasi (bukti arsitektur multi-database).

## Langkah 5 - Push ke GitHub

### 5a. Siapkan Git (sekali saja)
```powershell
git --version
git config --global user.name "Nama Kamu"
git config --global user.email "emailmu@contoh.com"
```
Pakai email yang sama dengan akun GitHub-mu.

### 5b. Buat repo lokal dan periksa isinya SEBELUM commit
```powershell
git init
git add .
git status
```
Periksa tiga hal berikut. **Ketiganya harus kosong/benar sebelum lanjut:**
```powershell
# 1. Tidak boleh ada file rahasia. Hasilnya harus KOSONG:
git ls-files | Select-String -Pattern '(^|/)\.env$'

# 2. Tidak boleh ada vendor/node_modules. Hasilnya harus KOSONG:
git ls-files | Select-String -Pattern 'vendor/|node_modules/'

# 3. Data bersih harus ikut (agar teman tidak perlu scraping). Harus tampil file CSV dan .ttl:
git ls-files data
```
Jika nomor 1 atau 2 menampilkan sesuatu, **jangan lanjut**. Kirim keluarannya kepadaku.
Cek juga password-mu tidak tertulis di file mana pun (ganti `PASSWORD_ANDA` dengan password aslimu). Hasilnya harus kosong:
```powershell
git grep -n "PASSWORD_ANDA"
```
(`git grep` hanya mencari di file yang sudah di-add, jadi `.env` yang di-ignore tidak ikut dan itu benar.)

> **Soal data TMDB di repo.** Aku menyarankan repo **privat** yang memuat `data\clean` dan `data\rdf`. Proyek ini akademik dan non-komersial,
> tetapi syarat penggunaan TMDB membatasi penyimpanan/penyebaran datanya. Aku belum memastikan bunyi pasal persisnya.
> Karena itu: jangan jadikan repo publik, dan jangan unggah folder `data\raw`. Jika ragu, tambahkan baris `data/clean/` dan
> `data/rdf/` ke `.gitignore` **sebelum** `git add .`, lalu minta tiap anggota menjalankan pipeline dengan token TMDB-nya sendiri.

### 5c. Commit pertama
```powershell
git commit -m "CINELOG: infrastruktur Docker, pipeline data, RDF/Fuseki, dan aplikasi Laravel awal"
```
Peringatan `LF will be replaced by CRLF` itu normal di Windows. **Harus terlihat:** `NNN files changed` (ratusan file karena Laravel).

### 5d. Buat repo kosong di GitHub
1. Buka https://github.com/new
2. Repository name: `cinelog`
3. Pilih **Private**.
4. **Jangan** centang "Add a README", ".gitignore", atau "license" (repo harus benar-benar kosong).
5. Klik **Create repository**, lalu salin URL yang tampil (bentuk `https://github.com/USERNAME/cinelog.git`).

### 5e. Hubungkan dan push
```powershell
git branch -M main
git remote add origin https://github.com/USERNAME/cinelog.git
git push -u origin main
```
Ganti `USERNAME` dengan username GitHub-mu. Saat pertama kali push, jendela browser terbuka untuk login GitHub; ikuti saja.
**Harus terlihat:** `Writing objects: 100%` dan `branch 'main' set up to track 'origin/main'`.

### 5f. Verifikasi di GitHub
Buka halaman repo-mu. Harus ada folder `database`, `data`, `docker`, `pipeline`, `web`, dan file `docker-compose.yml`.
**Tidak boleh ada** file bernama `.env` di mana pun. Cek folder `web` hanya berisi kode Laravel (tanpa folder `vendor`).

### 5g. Undang teman
Di halaman repo: **Settings > Collaborators > Add people**. Masukkan username GitHub teman-temanmu. Mereka menerima email undangan dan harus menerimanya dulu.

## Langkah 6 - Teman melanjutkan

Kirim ke teman:
1. URL repo.
2. Pesan: *"Buka `docs/PANDUAN-TEMAN.md` di repo, ikuti dari atas sampai `/status` menampilkan dua kotak hijau."*

Panduan itu mencakup pemasangan dari nol, cara bekerja bersama di Git, aturan yang tidak boleh dilanggar, dan usulan pembagian tugas.
Kamu sendiri sehari-hari cukup `git pull` sebelum mulai dan `git push` setelah selesai (detail ada di panduan itu).

---

## Masalah umum

| Gejala | Penyebab / solusi |
|---|---|
| `composer create-project` berkata folder tidak kosong | Isi `web` masih ada. `Rename-Item web web-lama`, lalu ulangi 2b. |
| `composer` mengeluh `ext-mongodb` atau versi PHP | Build image gagal sebagian. Jalankan `docker compose --profile app build --no-cache app` dan kirim log-nya jika masih gagal. |
| `/status` MariaDB: `Access denied` | Password di `web\.env` tidak cocok dengan database. Ulangi 3c, lalu `docker compose --profile app run --rm app php artisan config:clear`. |
| `/status` MariaDB: `Connection refused` / `getaddrinfo` | `DB_HOST` bukan `mariadb`. Ulangi 3c. Pastikan `docker compose ps` menunjukkan mariadb healthy. |
| `/status` MariaDB: `Base table ... doesn't exist` | Tabel belum terbentuk atau data belum diimpor. Jalankan `python pipeline\03_import_mysql.py`. |
| `/status` Fuseki: `cURL error 6/7` | Fuseki mati. `docker compose ps`, lalu `docker compose logs fuseki --tail 30`. |
| `/status` Fuseki tampil 0 film | Dataset belum dimuat. Jalankan `python pipeline\05_load_fuseki.py`. |
| Halaman putih / error 500 | `docker compose exec app tail -n 40 storage/logs/laravel.log`, lalu kirim keluarannya. |
| `localhost:8000` tidak bisa dibuka | `docker compose --profile app up -d app` lalu `docker compose logs app --tail 20`. |
| Poster tidak muncul | Butuh internet (gambar dari TMDB). Film tertentu memang tanpa poster. |
| `git push` ditolak (`rejected`/`403`) | Salah URL atau akun. Cek `git remote -v`. Pastikan login GitHub memakai akun pemilik repo. |
| `git push` lama / error file besar | Cek `git ls-files data`. File di atas 100 MB ditolak GitHub; kabari aku. |

## Ringkasan isi paket

| Berkas | Fungsi |
|---|---|
| `web-overlay\app\Models\*` | Model Eloquent untuk tabel yang sudah ada (Movie, Genre, Person, MovieCredit, Review, Watchlist, User) |
| `web-overlay\app\Services\SparqlClient.php` | Satu-satunya pintu aplikasi ke Fuseki (SELECT/ASK) |
| `web-overlay\app\Http\Controllers\*` | Katalog, halaman film, halaman status |
| `web-overlay\routes\web.php` | Rute `/`, `/film/{slug}`, `/status` |
| `web-overlay\config\cinelog.php` | Alamat Fuseki dan IRI dasar |
| `web-overlay\resources\views\*` | Tampilan Blade (Bootstrap dari CDN, tanpa Node/npm) |
| `scripts\configure_laravel_env.py` | Mengisi `web\.env` otomatis |
| `docs\PANDUAN-TEMAN.md` | Panduan untuk anggota kelompok lain |
| `.gitignore`, `.gitattributes` | Aturan Git (rahasia dan data mentah tidak ikut) |

## Tahap berikutnya (2B-2)
Halaman film yang memuat **RDFa + Open Graph**, daftar film yang diambil **lewat query SPARQL** (bukan hanya SQL), halaman genre dan orang,
serta tautan ke Wikidata. Kabari aku setelah `/status` menampilkan dua kotak hijau dan repo sudah di GitHub.
