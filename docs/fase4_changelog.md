# Fase 4 — Sedang Tayang

## Tujuan

Menampilkan film yang mempunyai jadwal pemutaran berstatus `scheduled` sedang berlangsung (waktu mulai sudah lewat tetapi waktu selesai belum lewat) atau akan dimulai dalam tujuh hari ke depan. Sumber data untuk halaman ini adalah knowledge graph RDF melalui Apache Jena Fuseki, bukan query langsung ke MariaDB atau berkas JSON bioskop.

## Alur data

```text
ScreeningEvent (RDF)
  ├── schema:workPresented ──> Movie
  ├── cin:heldAtCinema ──────> Cinema
  ├── cin:screenedInAuditorium -> Auditorium
  └── schema:startDate
          |
          v
ScreeningRepository
          |
          v
SparqlClient -> Fuseki /cinelog/sparql
          |
          v
ScreeningController
          |
          v
resources/views/sedang-tayang.blade.php
```

## File yang ditambahkan

- `web/app/Services/ScreeningRepository.php` — query SPARQL jadwal mendatang, mengelompokkan jadwal berdasarkan film, dan cache singkat 60 detik.
- `web/app/Http/Controllers/ScreeningController.php` — controller halaman Sedang Tayang dan penanganan kegagalan Fuseki.
- `web/resources/views/sedang-tayang.blade.php` — tampilan film, poster, bioskop, auditorium, waktu, format, dan harga tiket.

## File yang diubah

- `web/routes/web.php` — route `GET /sedang-tayang` dengan nama `screenings.now`.
- `web/resources/views/layouts/app.blade.php` — tautan navigasi Sedang Tayang.

## Aturan query

- Hanya `cin:ScreeningEvent` dengan `cin:screeningStatus "scheduled"` yang ditampilkan.
- Jadwal yang sedang berlangsung ikut ditampilkan jika `schema:startDate` sudah lewat dan `schema:endDate` belum lewat.
- Jadwal mendatang ikut ditampilkan jika `schema:startDate` berada antara waktu saat ini (UTC) dan tujuh hari setelahnya.
- Film, bioskop, auditorium, koordinat, format, dan harga diambil melalui relasi RDF/Schema.org yang sudah ada.
- Tampilan mengonversi waktu ke `Asia/Jakarta` untuk label WIB.
- Dataset jadwal adalah data simulasi akademik, bukan jadwal resmi real-time dari jaringan bioskop.

## Validasi yang dilakukan pada source ZIP

- PHP syntax check: `ScreeningRepository.php`, `ScreeningController.php`, dan `routes/web.php` lulus `php -l`.
- Query SPARQL yang setara diuji terhadap RDF lokal menggunakan RDFLib dan mengembalikan jadwal yang cocok dengan kondisi waktu saat pengujian; query memuat event yang sedang berlangsung dan event mendatang dalam jendela tujuh hari.
- Runtime Laravel/Fuseki belum diverifikasi dari lingkungan penyusunan ZIP ini karena dependency `web/vendor` tidak tersedia. Verifikasi runtime perlu dilakukan pada komputer pengembang yang menjalankan Docker Compose.

## Verifikasi di komputer pengembang

Dari root proyek:

```powershell
docker compose ps
# Pastikan cinelog-fuseki berstatus Up

# Buka halaman melalui aplikasi Laravel
Start-Process http://localhost:8000/sedang-tayang
```

Jika halaman gagal mengambil data, periksa konfigurasi `FUSEKI_URL` pada environment container aplikasi. Dari dalam container `app`, host Fuseki harus menggunakan nama service Docker, yaitu `http://fuseki:3030/cinelog`, bukan `localhost:3030`.
