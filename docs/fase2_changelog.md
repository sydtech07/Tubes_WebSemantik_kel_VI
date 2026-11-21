# Catatan implementasi Fase 2 — Dataset Bioskop + Screening

## Tujuan

Fase 2 mengisi dua bagian knowledge graph yang sebelumnya masih kosong:

- `cin:Auditorium`
- `cin:ScreeningEvent`

Dataset ini **dataset buatan sendiri untuk kebutuhan tugas Web Semantik**, bukan salinan jadwal resmi bioskop. Nama bioskop, kota, alamat, dan koordinat tetap berasal dari direktori bioskop proyek; sedangkan studio, kapasitas, jadwal, harga, format tayang, dan status jadwal dibuat secara sintetis dan ditandai dalam dokumentasi sebagai data simulasi.

## Hasil

| Komponen | Jumlah |
|---|---:|
| Bioskop | 13 |
| Koordinat bioskop | 13 |
| Auditorium | 26 |
| Film yang dijadwalkan | 8 |
| Jadwal tayang | 728 |
| Periode jadwal | 17–23 November 2026 |
| Triple RDF | 152.041 |
| Uji SPARQL lokal | 22/22 valid |

Setiap bioskop memiliki dua auditorium sintetis (`Studio 1` dan `Studio 2`). Setiap hari tersedia empat slot pada masing-masing auditorium: 10:00, 13:00, 16:00, dan 19:00 WIB. Jadwal menggunakan film yang sudah ada di `data/clean/movies.csv` sehingga setiap `schema:workPresented` dapat direferensikan ke resource film yang valid.

## Reproduksibilitas

Dataset dapat dibuat ulang dengan:

```powershell
python pipeline/09_seed_cinema_schedule.py --start 2026-11-17 --days 7
python pipeline/04_build_rdf.py
python pipeline/06_validate_sparql.py --ttl data/rdf/cinelog-data.ttl
```

Generator sengaja tidak mengisi `source_url` atau `ticket_url` pada jadwal sintetis. Jangan mempresentasikan harga, studio, atau jam pada CSV ini sebagai jadwal resmi bioskop.

## Validasi relasi semantik

Setiap jadwal valid harus memiliki:

- `cin:ScreeningEvent`
- `schema:workPresented` → `cin:Movie`
- `cin:heldAtCinema` → `cin:Cinema`
- `schema:startDate` dengan timezone `+07:00`
- `cin:screenedInAuditorium` → `cin:Auditorium`
- `cin:partOfCinema` pada auditorium yang sama dengan bioskop jadwal
- `schema:offers` → `schema:Offer` untuk harga yang valid

Pipeline memvalidasi referensi film/bioskop/auditorium, ID unik, waktu ISO-8601 dengan timezone, urutan waktu mulai/selesai, kapasitas, koordinat, harga, dan kode mata uang.

## Posisi setelah Fase 2

- **Fase 1 — Ontologi + RDF Pipeline:** selesai.
- **Fase 2 — Dataset Bioskop + Screening:** selesai.
- **Fase 3 — Fuseki + SPARQL:** dataset siap dimuat; verifikasi runtime Fuseki masih perlu dilakukan pada komputer yang menjalankan Docker.
- **Fase 4 — Sedang Tayang + Maps:** belum dimulai.
