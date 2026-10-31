# CINÉLOG

Katalog dan ulasan film rilis Januari-September 2026 berbasis data semantik. Tugas besar Web Semantik dan Manajemen Sistem Basis Data, kelompok VI.
Data film (sumber: TMDB) diolah menjadi RDF, disimpan di triple store, dan ditampilkan lewat aplikasi Laravel. Tiap jenis data memakai basis data yang paling cocok:
| Data | Penyimpanan | Alamat lokal |
|---|---|---|
| Film, genre, orang, kru/pemeran (RDF, query SPARQL) | Apache Jena Fuseki | http://localhost:3030 |
| Pengguna, ulasan, rating, watchlist (transaksional, trigger) | MariaDB | `localhost:3307` |
| Riwayat chatbot | MongoDB | `localhost:27017` |
| Aplikasi web | Laravel 13 (PHP 8.3) | http://localhost:8000 |

## Struktur folder

| Folder | Isi |
|---|---|
| `pipeline/` | skrip Python 01-06: ambil TMDB, bersihkan, impor MariaDB, bangun RDF, muat ke Fuseki, validasi SPARQL |
| `ontology/` | ontologi `cinelog.ttl` |
| `data/` | CSV bersih (`clean/`) dan RDF Turtle (`rdf/`) |
| `database/` | skema, trigger, dan stored procedure MariaDB (`sql/`), serta tes SQL (`tests/`) |
| `sparql/` | query SPARQL (federasi Wikidata) |
| `docker/`, `docker-compose.yml` | Fuseki, MariaDB, MongoDB, aplikasi Laravel |
| `web/` | aplikasi Laravel |
| `scripts/` | alat bantu: konfigurasi `.env` Laravel, bukti RDFa, perapian repo |
| `docs/` | panduan tim dan hasil pengujian |
