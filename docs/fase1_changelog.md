# Catatan implementasi Fase 1 — Knowledge Graph Bioskop

Baseline yang digunakan: `Tubes_WebSemantik_kel_VI(1).zip`, commit `93083c2` (`Tambah login, rating dan ulasan, watchlist, profil, dan dasbor admin`). Tidak ada file aplikasi Laravel atau fitur autentikasi/komunitas yang diubah dalam fase ini.

## Perubahan

1. `ontology/cinelog.ttl`
   - Menambahkan kelas `cin:Cinema`, `cin:Auditorium`, `cin:ScreeningEvent`, dan `cin:GeoCoordinates`.
   - Memetakan kelas ke Schema.org: `schema:MovieTheater`, `schema:Place`, `schema:ScreeningEvent`, dan `schema:GeoCoordinates`.
   - Menambahkan properti relasi untuk auditorium, jadwal tayang, dan koordinat.
   - Menambahkan properti data jaringan bioskop, kota, kecamatan, sumber koordinat, nomor layar, format auditorium, dan status jadwal.

2. `pipeline/rdfcommon.py`
   - Menambahkan pembaca direktori bioskop JSON yang dipakai bersama oleh halaman peta Laravel.
   - Menambahkan pembaca CSV opsional untuk data auditorium dan jadwal.

3. `pipeline/04_build_rdf.py`
   - Mempertahankan seluruh proses RDF film, orang, genre, keyword, dan cast/crew.
   - Membangun RDF untuk bioskop dan koordinat.
   - Mendukung impor auditorium dan jadwal tayang jika CSV berisi data yang valid.
   - Memvalidasi referensi film/bioskop/auditorium, format tanggal ISO-8601 dengan zona waktu, koordinat, kapasitas, dan harga.
   - Menambahkan hitungan kelas baru ke `data/rdf/rdf_report.json`.

4. `pipeline/06_validate_sparql.py`
   - Menambahkan enam uji SPARQL untuk jumlah bioskop, koordinat, distribusi jaringan, auditorium, jadwal tayang, dan relasi inti jadwal.

5. Dokumentasi dan query
   - Menambahkan template `data/clean/auditoriums.csv` dan `data/clean/screenings.csv`.
   - Menambahkan panduan format CSV pada `docs/data_semantik_bioskop.md`.
   - Menambahkan query SPARQL contoh pada `sparql/`.
   - Memperbarui README dan laporan hasil validasi.

## Hasil validasi lokal

| Item | Jumlah |
|---|---:|
| Film | 500 |
| Orang | 7.807 |
| Genre | 19 |
| Keyword | 2.342 |
| Peran pemeran | 6.952 |
| Bioskop | 13 |
| Koordinat bioskop | 13 |
| Auditorium | 0 |
| Jadwal tayang | 0 |
| Triple data RDF | 139.405 |
| Triple data + ontologi (perkiraan lokal untuk loader) | 139.572 |
| Uji SPARQL lokal | 22/22 valid |

Nol auditorium dan jadwal bukan kegagalan pipeline. Baseline tidak menyertakan data faktual untuk dua kategori tersebut; template CSV disediakan agar data dapat ditambahkan setelah diverifikasi.

## Aktivasi di komputer pengembang

Jalankan dari folder utama proyek setelah Docker Desktop dan service Fuseki aktif:

```powershell
python pipeline/04_build_rdf.py
python pipeline/05_load_fuseki.py
python pipeline/06_validate_sparql.py --endpoint http://localhost:3030/cinelog
```

Validasi lokal tanpa Docker:

```powershell
python pipeline/06_validate_sparql.py --ttl data/rdf/cinelog-data.ttl
```

Fase ini belum mengubah sumber data peta Laravel. Penyambungan halaman Maps ke data Fuseki adalah pekerjaan fase berikutnya.
