# Data semantik bioskop CINÉLOG

Fase 1 menambahkan direktori bioskop ke knowledge graph. Fase 2 kemudian mengisi auditorium dan jadwal tayang menggunakan **dataset buatan sendiri** untuk demonstrasi Web Semantik.

## Sumber dan status data

### Data direktori bioskop

- 13 bioskop dari `web/resources/data/bioskop-sumut.json`.
- Koordinat lintang/bujur untuk 13 bioskop.
- Kolom `coord` tetap dimasukkan ke RDF sebagai `cin:coordinateSource`; nilai `estimasi` berarti koordinat perkiraan dan tidak boleh dipresentasikan sebagai presisi survei.

### Data auditorium dan jadwal

- 26 auditorium: dua studio sintetis untuk setiap bioskop.
- 728 jadwal tayang sintetis untuk 17–23 November 2026.
- 8 film dipilih dari `data/clean/movies.csv`.
- Harga, kapasitas, format tayang, subtitle, dan waktu tayang adalah **data simulasi buatan proyek**.
- `source_url` dan `ticket_url` sengaja dikosongkan karena data tersebut bukan jadwal resmi dari situs bioskop.

Jangan menyebut data simulasi ini sebagai jadwal aktual ketika melakukan presentasi atau penulisan laporan.

## Membuat ulang dataset

```powershell
python pipeline/09_seed_cinema_schedule.py --start 2026-11-17 --days 7
```

Argumen `--start` dan `--days` dapat digunakan untuk membuat periode demo lain. Setelah CSV berubah, bangun ulang RDF:

```powershell
python pipeline/04_build_rdf.py
```

## Format `auditoriums.csv`

| Kolom | Wajib | Keterangan |
|---|---|---|
| `auditorium_id` | Ya | ID unik auditorium |
| `cinema_id` | Ya | ID dari `bioskop-sumut.json` |
| `name` | Ya | Nama studio |
| `screen_number` | Tidak | Nomor layar positif |
| `capacity` | Tidak | Kapasitas; pada dataset ini nilai sintetis |
| `format` | Tidak | Format auditorium; pada dataset ini `Regular 2D` |

## Format `screenings.csv`

| Kolom | Wajib | Keterangan |
|---|---|---|
| `screening_id` | Ya | ID unik jadwal |
| `movie_tmdb_id` | Ya | ID film pada `data/clean/movies.csv` |
| `cinema_id` | Ya | ID bioskop |
| `auditorium_id` | Tidak | Auditorium milik bioskop yang sama |
| `start_datetime` | Ya | ISO-8601 dengan timezone, misalnya `2026-11-17T19:00:00+07:00` |
| `end_datetime` | Tidak | ISO-8601 dengan timezone dan harus setelah mulai |
| `video_format` | Tidak | Format tayang; dataset ini menggunakan `2D` |
| `subtitle_language` | Tidak | Dataset ini menggunakan `id` |
| `price` | Tidak | Harga sintetis non-negatif |
| `currency` | Bersama harga | Kode ISO 4217; dataset ini `IDR` |
| `status` | Tidak | Status dataset; dataset ini `scheduled` |
| `ticket_url` | Tidak | Dikosongkan untuk data simulasi |
| `source_url` | Tidak | Dikosongkan untuk data simulasi |

Baris dengan ID duplikat, referensi film/bioskop yang tidak ditemukan, timezone yang tidak eksplisit, waktu selesai yang tidak valid, atau auditorium yang tidak cocok dengan bioskop akan dilewati dan dicatat dalam `data/rdf/rdf_report.json`.

## Model RDF

- `cin:Cinema` adalah subclass `schema:MovieTheater`.
- `cin:Auditorium` adalah subclass `schema:Place`, terhubung ke bioskop dengan `cin:hasAuditorium` / `cin:partOfCinema`.
- `cin:ScreeningEvent` adalah subclass `schema:ScreeningEvent`, terhubung ke film melalui `schema:workPresented`, ke bioskop melalui `schema:location` / `cin:heldAtCinema`, dan ke auditorium melalui `cin:screenedInAuditorium`.
- `cin:GeoCoordinates` adalah subclass `schema:GeoCoordinates`, ditautkan dari bioskop melalui `schema:geo` / `cin:hasGeoCoordinates`.
- Harga tiket dimodelkan sebagai `schema:Offer` melalui `schema:offers`.

## Query SPARQL

Query contoh ada pada folder `sparql/`, terutama `screenings.rq` untuk mengambil jadwal, film, bioskop, auditorium, format, status, dan harga.
