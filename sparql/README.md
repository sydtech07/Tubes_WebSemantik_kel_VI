# Query SPARQL bioskop

Jalankan query berikut pada dataset `cinelog` di Fuseki (`http://localhost:3030`):

- `cinemas_with_coordinates.rq` — data bioskop, jaringan, kota, dan koordinat untuk peta.
- `auditoriums_by_cinema.rq` — daftar auditorium per bioskop.
- `screenings.rq` — jadwal, film, bioskop, auditorium, format, dan harga.
- `cinema_counts_by_chain.rq` — jumlah bioskop berdasarkan jaringan.

Prefix `cin:` pada file contoh menggunakan base URI default `http://localhost:8000/`. Jika `BASE_URI` di pipeline diubah, sesuaikan prefix tersebut.
