# Panduan Anggota Kelompok: Menjalankan dan Melanjutkan CINÉLOG

Tubes Web Semantik + MSBD. Satu website: katalog dan review 500 film (Jan-Sep 2026), data semantik di Fuseki (RDF/SPARQL),
data transaksional di MariaDB, riwayat chatbot di MongoDB, aplikasinya Laravel.

Semua perintah dijalankan di **PowerShell**, dari folder root proyek (yang berisi `docker-compose.yml`).
Ikuti urut dari atas. Tiap langkah punya **"Harus terlihat"**; kalau tidak cocok, berhenti dan tanya.

## A. Pasang alat (sekali saja)

| Alat | Cek dengan | Catatan |
|---|---|---|
| Docker Desktop | `docker --version` dan `docker compose version` | Windows memakai WSL2; Docker Desktop harus **sedang berjalan** |
| Git | `git --version` | |
| Python 3.11+ | `python --version` | Centang "Add python.exe to PATH" saat instalasi |
| VS Code (disarankan) | | untuk mengedit kode |

PHP, Composer, dan Node **tidak perlu** dipasang; semuanya berjalan di Docker.

## B. Ambil proyek

```powershell
git config --global user.name "Nama Kamu"
git config --global user.email "emailmu@contoh.com"
git clone URL_REPO_DARI_TEMAN cinelog
cd cinelog
```
(Pertama kali, browser terbuka untuk login GitHub. Kamu harus sudah menerima undangan collaborator lewat email.)

## C. Siapkan file pengaturan (rahasia, tidak ikut Git)

```powershell
copy .env.example .env
copy pipeline\.env.example pipeline\.env
```
1. Buka `.env`, ganti semua `ganti_...` dengan password buatanmu sendiri (bebas, hanya untuk komputermu).
2. Buka `pipeline\.env`, isi `DB_PASSWORD` dengan nilai **yang sama** seperti `DB_PASSWORD` di `.env`.
   Token TMDB **tidak perlu** diisi karena data film sudah ada di repo.

## D. Nyalakan database

```powershell
docker compose up -d --build
docker compose ps
```
Build pertama mengunduh Apache Jena (ratusan MB), butuh beberapa menit. **Harus terlihat:** `cinelog-mariadb` (healthy), `cinelog-fuseki` (Up), `cinelog-mongo` (Up).
Buka http://localhost:3030 : halaman Fuseki tampil.

## E. Isi database dengan 500 film

```powershell
python -m venv pipeline\.venv
pipeline\.venv\Scripts\Activate.ps1
python -m pip install -r pipeline\requirements.txt
python pipeline\03_import_mysql.py
python pipeline\05_load_fuseki.py
```
- Jika `Activate.ps1` ditolak ("running scripts is disabled"): `Set-ExecutionPolicy -Scope CurrentUser RemoteSigned`, jawab `Y`, ulangi.
- Prompt PowerShell berawalan `(.venv)` jika venv aktif.
- **Harus terlihat** (impor): jumlah film 500 tanpa error. **Harus terlihat** (Fuseki): `Verifikasi berhasil: jumlah triple cocok.`

Data RDF (`data\rdf\cinelog-data.ttl`) sudah ada di repo, jadi langkah `04_build_rdf.py` tidak perlu diulang kecuali data CSV berubah.

## F. Nyalakan Laravel

```powershell
docker compose --profile app build app
docker compose --profile app run --rm app composer install
copy web\.env.example web\.env
docker compose --profile app run --rm app php artisan key:generate
python scripts\configure_laravel_env.py
docker compose --profile app up -d app
```
Buka http://localhost:8000/status : dua kotak **TERHUBUNG** (MariaDB: film 500; Fuseki: film di RDF 500). Lalu buka http://localhost:8000/ untuk katalog.
Build pertama 5 sampai 10 menit. Selesai: kamu punya proyek yang sama persis dengan pemilik repo.

> Tiap orang punya database **sendiri di komputernya**. Akun dan review yang kamu buat di komputermu tidak muncul di komputer teman.
> Itu normal untuk pengembangan; kita menyatukannya nanti saat demo di satu komputer.

---

## G. Bekerja sehari-hari

Kode aplikasi ada di folder **`web\`**. Folder itu terhubung ke container, jadi cukup simpan file lalu *refresh* browser.

Perintah Laravel dijalankan lewat container (setelah `up -d app`):
```powershell
docker compose exec app php artisan route:list
docker compose exec app php artisan make:controller NamaController
docker compose logs app --tail 30
docker compose exec app tail -n 40 storage/logs/laravel.log     # log error aplikasi
```
Mulai kerja: `docker compose up -d` lalu `docker compose --profile app up -d app`. Selesai: `docker compose stop` (data aman).

### Alur Git (wajib dipatuhi agar tidak saling menimpa)

```powershell
git switch main
git pull                                   # SELALU sebelum mulai
git switch -c fitur/nama-fitur             # satu cabang per fitur, contoh: fitur/chatbot
# ... ubah kode, uji di browser ...
git add .
git status                                 # periksa: tidak ada .env, tidak ada vendor/
git commit -m "chatbot: intent pencarian berdasarkan genre"
git push -u origin fitur/nama-fitur
```
Lalu di GitHub klik **Compare & pull request**, minta satu teman membaca, kemudian **Merge**. Setelah merge: `git switch main; git pull`.
Pesan commit ditulis jelas dan singkat, berawalan nama bagian (`chatbot:`, `film:`, `sparql:`, `db:`, `docs:`).
Konflik: kalau `git pull` melaporkan *conflict*, jangan panik dan jangan hapus apa pun; kirim tangkapan layar ke grup.

### Aturan yang tidak boleh dilanggar

1. **Jangan** menjalankan `php artisan migrate` atau `migrate:fresh`. Tabel dibuat oleh `database\sql\*.sql`; `migrate:fresh` menghapus semua tabel.
2. **Jangan** commit `.env`, `pipeline\.env`, atau `web\.env` (sudah di-ignore; periksa `git status` sebelum commit). Jangan tempel password/token di chat grup.
3. **Jangan** mengubah `database\sql\01_schema.sql` atau `02_routines.sql` tanpa memberi tahu semua orang. Perubahan skema hanya berlaku di database baru, jadi semua anggota harus mereset (lihat bagian H).
4. **Jangan** mengunggah `data\raw\` (sudah di-ignore) dan jangan menjadikan repo publik (data TMDB).
5. Semua query ke Fuseki lewat `app\Services\SparqlClient.php`; semua akses tabel lewat model Eloquent di `app\Models`. Jangan menulis koneksi baru di controller.
6. Halaman web yang mengandung rating/review hanya menulis ke tabel `reviews` (trigger yang menghitung rata-rata). Jangan mengisi `movies.avg_rating` secara manual.

## H. Reset total (jika data rusak atau skema berubah)

```powershell
docker compose --profile app down -v         # -v MENGHAPUS semua data database di komputermu
docker compose up -d --build
# tunggu mariadb healthy, lalu ulangi bagian E (impor + muat Fuseki) dan jalankan:
docker compose --profile app up -d app
```

## I. Masalah umum

| Gejala | Solusi |
|---|---|
| `port is already allocated` | Port 3307, 3030, 27017, atau 8000 dipakai program lain (XAMPP, MySQL lokal). Hentikan program itu, atau minta bantuan mengganti port. |
| `/status` MariaDB gagal `Access denied` | Password `web\.env` tidak cocok. Jalankan ulang `python scripts\configure_laravel_env.py`, lalu `docker compose exec app php artisan config:clear`. |
| `/status` Fuseki 0 film | `python pipeline\05_load_fuseki.py` (venv aktif). |
| Fuseki terus *Restarting* | `docker compose logs fuseki --tail 50`, kirim ke grup. |
| `ModuleNotFoundError` | venv belum aktif atau belum `pip install`. Ulangi bagian E. |
| Browser menampilkan halaman lama | Tekan `Ctrl+F5`. |

## J. Usulan pembagian tugas (sesuaikan dengan jumlah anggota)

Urutan tahap: ketergantungan dari atas ke bawah. Tahap 1 dikerjakan pemilik repo bersama asisten; sisanya bisa paralel setelah tahap 1 selesai.

| Tahap | Isi | Keluaran yang bisa dicek |
|---|---|---|
| 2B-2 | Halaman film dengan RDFa + Open Graph; daftar film via SPARQL; halaman genre/orang; tautan Wikidata | Validator RDFa/OGP menampilkan triple; query SPARQL dipakai di halaman |
| 2B-3 | Registrasi/login, form rating+review (lewat `sp_upsert_review`), watchlist | Rating baru mengubah `avg_rating` film (trigger) |
| 2B-4 | Integrasi Wikidata dari PHP (negara asal, box office, dll.) | Data eksternal tampil di halaman film |
| 2B-5 | Chatbot rekomendasi (maksud -> template SPARQL -> Fuseki), riwayat di MongoDB | Tanya "film horor terbaik" menghasilkan daftar film nyata |
| Akhir | Pengujian, tabel validasi, paper jurnal, presentasi (arsitektur, demo, kode) | Dokumen dan slide |

Tulis nama penanggung jawab tiap tahap di grup, dan satu cabang Git per tahap.
