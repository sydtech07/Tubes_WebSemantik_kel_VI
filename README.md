# CINÉLOG

Katalog dan ulasan film rilis Januari-September 2026 berbasis data semantik. Tugas besar Web Semantik dan Manajemen Sistem Basis Data, kelompok VI.
Data film (sumber: TMDB) diolah menjadi RDF, sedangkan dataset bioskop, auditorium, dan jadwal tayang disusun sebagai dataset proyek; seluruh data semantik disimpan di triple store dan ditampilkan lewat aplikasi Laravel. Tiap jenis data memakai basis data yang paling cocok:
| Data | Penyimpanan | Alamat lokal |
|---|---|---|
| Film, genre, orang, kru/pemeran, bioskop, koordinat, auditorium, dan jadwal tayang (RDF/SPARQL) | Apache Jena Fuseki | http://localhost:3030 |
| Pengguna, ulasan, rating, watchlist (transaksional, trigger) | MariaDB | `localhost:3307` |
| Riwayat chatbot | MongoDB | `localhost:27017` |
| Aplikasi web | Laravel 13 (PHP 8.3) | http://localhost:8000 |


## Memperbarui data film terbaru dari TMDB

Pipeline TMDB menggunakan endpoint `/discover/movie` untuk mengambil film yang berada dalam rentang tanggal terbaru, kemudian mengambil detail, pemeran, kru, keyword, ID IMDb, dan Wikidata. Secara default, pipeline menggunakan **365 hari terakhir** dan mengurutkan kandidat berdasarkan `primary_release_date.desc`, sehingga film yang lebih baru diprioritaskan. TMDB memang menyediakan filter tanggal rilis dan opsi pengurutan pada endpoint Discover.

Jalankan dari root proyek:

```powershell
python pipeline/01_fetch_tmdb.py --latest-days 365 --target 500 --refresh
python pipeline/02_clean.py --latest-days 365 --max 500
python pipeline/09_seed_cinema_schedule.py --start 2026-11-18 --days 7
python pipeline/04_build_rdf.py
python pipeline/05_load_fuseki.py
python pipeline/06_validate_sparql.py --endpoint http://localhost:3030/cinelog
```

`--refresh` penting ketika ingin metadata TMDB untuk film kandidat diperbarui, bukan sekadar memakai cache lama. Jadwal bioskop tetap **data sintetis untuk tugas**, bukan jadwal resmi TMDB; setelah film diperbarui, `09_seed_cinema_schedule.py` otomatis memilih 8 film dari `movies.csv` sehingga tidak bergantung pada ID film lama.

Untuk fokus yang lebih sempit, misalnya enam bulan terakhir:

```powershell
python pipeline/01_fetch_tmdb.py --latest-days 180 --target 300 --refresh
python pipeline/02_clean.py --latest-days 180 --max 300
```

## Struktur folder

| Folder | Isi |
|---|---|
| `pipeline/` | skrip Python untuk ambil TMDB, bersihkan, impor MariaDB, bangun RDF film/bioskop, muat ke Fuseki, dan validasi SPARQL |
| `ontology/` | ontologi `cinelog.ttl` |
| `data/` | CSV film dan dataset auditorium/jadwal (`clean/`) serta RDF Turtle/laporan (`rdf/`) |
| `database/` | skema, trigger, dan stored procedure MariaDB (`sql/`), serta tes SQL (`tests/`) |
| `sparql/` | query SPARQL bioskop dan federasi Wikidata |
| `docker/`, `docker-compose.yml` | Fuseki, MariaDB, MongoDB, aplikasi Laravel |
| `web/` | aplikasi Laravel |
| `scripts/` | alat bantu: konfigurasi `.env` Laravel dan validasi RDFa |
| `docs/` | hasil pengujian SPARQL |

## Akun admin

Daftarkan akun di aplikasi, lalu jalankan `docker compose exec app php artisan cinelog:admin email-admin@example.com` dari folder utama proyek untuk menjadikannya admin. Ganti email dengan akun yang terdaftar. Dashboard tersedia di `/admin`.


## Knowledge graph bioskop (Fase 1–2)

Ontologi CINÉLOG kini mencakup `cin:Cinema`, `cin:Auditorium`, `cin:ScreeningEvent`, dan `cin:GeoCoordinates`, yang dipadankan dengan tipe Schema.org (`schema:MovieTheater`, `schema:Place`, `schema:ScreeningEvent`, dan `schema:GeoCoordinates`). Data bioskop diambil dari `web/resources/data/bioskop-sumut.json`, yaitu sumber yang sama dengan direktori peta Laravel.

Dataset saat ini menghasilkan 13 bioskop, 13 titik koordinat, 26 auditorium sintetis, dan 728 jadwal tayang sintetis untuk periode 17–23 November 2026. Jadwal dan kapasitas tersebut adalah dataset buatan sendiri untuk tugas, bukan jadwal resmi bioskop. Detail dan generator reproduksinya ada di `docs/fase2_changelog.md`.

### Bangun, muat, dan validasi RDF

Dari folder utama proyek:

```powershell
# Pastikan Fuseki aktif

docker compose up -d fuseki

# Buat dataset auditorium + jadwal tayang sintetis (dataset buatan sendiri)
python pipeline/09_seed_cinema_schedule.py --start 2026-11-17 --days 7

# Bangun ulang dataset RDF (mempertahankan film, orang, genre, keyword, bioskop, dan screening)
python pipeline/04_build_rdf.py

# Muat dataset dan ontologi ke Fuseki yang sudah berjalan
python pipeline/05_load_fuseki.py

# Validasi lokal tanpa perlu menghubungi Fuseki
python pipeline/06_validate_sparql.py --ttl data/rdf/cinelog-data.ttl

# Validasi endpoint Fuseki yang sedang berjalan
python pipeline/06_validate_sparql.py --endpoint http://localhost:3030/cinelog
```

Query contoh untuk knowledge graph bioskop tersedia pada `sparql/README.md`. Setelah dataset RDF diperbarui, jalankan kembali `pipeline/05_load_fuseki.py` agar perubahan termuat ke Fuseki. Skrip loader mengganti graph default dengan dataset terbaru lalu menambahkan ontologi.


## Halaman Sedang Tayang (Fase 4)

Halaman `http://localhost:8000/sedang-tayang` menampilkan film yang memiliki `cin:ScreeningEvent` berstatus `scheduled` yang sedang berlangsung atau akan dimulai dalam tujuh hari ke depan. Query dilakukan melalui `ScreeningRepository` → `SparqlClient` → Apache Jena Fuseki. Data jadwal, bioskop, auditorium, dan harga dibaca dari RDF. Jadwal yang tersedia adalah dataset simulasi akademik, bukan jadwal resmi real-time.

Detail implementasi dan status validasi terdapat di `docs/fase4_changelog.md`. Setelah container aplikasi dan Fuseki aktif, buka halaman tersebut untuk menguji integrasi runtime.
