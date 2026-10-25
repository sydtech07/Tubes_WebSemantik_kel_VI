# Fase 2B-2 - Katalog, pencarian, dan halaman film lewat SPARQL (RDFa + Open Graph)

## Ringkasan

Katalog, pencarian, filter genre, dan halaman `/film/{slug}` mengambil data dari Fuseki lewat SPARQL.
Rating dan jumlah ulasan tetap dari MariaDB (dihitung trigger). Halaman film memuat RDFa 1.1 dan Open Graph (`video.movie`).

```
Browser -> HomeController / FilmController -> MovieRepository -> SparqlClient -> Fuseki  (judul, genre, kru, pemeran, tautan)
                                           \-> Eloquent Movie -> MariaDB                  (avg_rating, reviews_count)
```

Pembagian ini disengaja: data deskriptif ada di triple store, sedangkan rating pengguna bersifat transaksional dan dihitung trigger.

## Berkas yang terlibat

| Berkas | Peran |
|---|---|
| `web/app/Services/SparqlClient.php` | satu-satunya pintu ke Fuseki; `lit()`/`iri()` meng-escape input sebelum masuk query; `describe()` untuk Turtle |
| `web/app/Services/MovieRepository.php` | semua query SPARQL: `catalog()`, `genres()` (cache 1 jam), `detail()` |
| `web/app/Http/Controllers/HomeController.php` | katalog: pencarian, genre, urutan, paginasi |
| `web/app/Http/Controllers/FilmController.php` | halaman film |
| `web/app/Http/Controllers/ResourceController.php` | `/resource/movie/{id}`: `Accept: text/turtle` -> data RDF, selain itu 303 ke halaman film |
| `web/app/Models/*.php` | model Eloquent: Movie, Genre, Person, MovieCredit, Review, User, Watchlist |
| `web/resources/views/{home,film}.blade.php`, `layouts/app.blade.php` | tampilan; RDFa di `film.blade.php`, Open Graph lewat `@push('head')` |
| `web/tests/Unit/MovieRepositoryTest.php` | 4 unit test tanpa Fuseki |
| `scripts/check_rdfa.py` | mengurai RDFa halaman film dan mencocokkannya dengan Fuseki |

## Daftar periksa pengujian

Isi sendiri dan simpan tangkapan layarnya untuk paper.

- [ ] `/` menampilkan 24 poster, "500 film", chip genre, dan paginasi
- [ ] `/?q=spider` hanya menampilkan judul yang memuat "spider"
- [ ] `/?genre=18` menampilkan 174 film (sama dengan uji SPARQL no. 3 di `docs/hasil_uji_sparql.md`)
- [ ] `/?genre=18&sort=judul` terurut A-Z
- [ ] `/?q=a%22%20%7D%20UNION%20%7B` menampilkan "Tidak ada film yang cocok", bukan error 500
- [ ] halaman film menampilkan sinopsis, genre, kru, pemeran, dan tautan eksternal
- [ ] `curl.exe -i http://localhost:8000/resource/movie/<tmdb_id>` menjawab `303 See Other`
- [ ] `curl.exe -H "Accept: text/turtle" http://localhost:8000/resource/movie/<tmdb_id>` menjawab Turtle
- [ ] `python scripts\check_rdfa.py <slug> --md docs\hasil_uji_rdfa.md` melaporkan `Tidak di Fuseki: 0`
- [ ] `docker compose --profile app exec app php artisan test` lulus

## Masalah umum

| Gejala | Penyebab / solusi |
|---|---|
| `localhost:8000` atau `localhost:3030` sangat lambat, tetapi `127.0.0.1` cepat | Browser Windows mencoba IPv6 (`::1`) dulu dan menggantung di Docker Desktop. Sudah diatasi dengan mengikat semua port ke `127.0.0.1` di `docker-compose.yml`. |
| Satu halaman macet membuat semua tab ikut menunggu | `artisan serve` hanya melayani satu permintaan sekaligus. Sudah diatasi dengan `PHP_CLI_SERVER_WORKERS: "4"`. |
| Kotak merah "Data film tidak dapat diambil dari Fuseki" | Fuseki mati atau `FUSEKI_URL` salah. Lihat `/status`, lalu `docker compose logs fuseki --tail 30`. |
| Katalog kosong padahal `/status` menunjukkan 500 film | `CINELOG_BASE_URI` di `web\.env` tidak sama dengan `BASE_URI` saat RDF dibuat (`http://localhost:8000/`). |
| Chip genre kosong atau salah | Cache lama: `docker compose --profile app exec app php artisan cache:clear`. |
| Pencarian "amelie" tidak menemukan "Amélie" | Pencarian tidak menghapus aksen (hanya `LCASE`). Batasan yang disengaja. |

## Batasan (jujur untuk paper)

- `schema:aggregateRating` di RDFa berasal dari MariaDB, jadi tidak ada di Fuseki. `check_rdfa.py` menandainya sebagai blank node, bukan kesalahan.
- Hanya IRI film dan genre yang bisa dibuka. IRI orang (`/resource/person/...`) tetap berfungsi sebagai identitas di RDF, tetapi belum punya halaman.
- Peran pemeran (`cin:CastRole`) tidak ditulis ke RDFa, hanya `schema:actor`.
- Anggaran/pendapatan di bawah 1000 USD disembunyikan karena itu placeholder TMDB.
- Jika Fuseki mati, halaman menampilkan error yang jelas; tidak ada cadangan data dari MariaDB.
