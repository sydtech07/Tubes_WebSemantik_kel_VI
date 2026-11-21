# Hasil uji validasi SPARQL (mode endpoint)

## Tabel A. Hasil pengujian uji kasus menggunakan query SPARQL

| No | Uji Kasus | Statement Kueri SPARQL | Hasil diharapkan (dari CSV) | Hasil aktual | Status |
|---|---|---|---|---|---|
| 1 | Hitung seluruh film | `SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m a cin:Movie }` | 500 | 500 | Valid |
| 2 | Hitung seluruh genre | `SELECT (COUNT(DISTINCT ?g) AS ?n) WHERE { ?g a cin:Genre }` | 19 | 19 | Valid |
| 3 | Tampilkan film bergenre "Drama" (genre paling banyak) | `SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:genre ?g . ?g rdfs:label "Drama"@en }` | 189 | 189 | Valid |
| 4 | Distribusi jumlah film untuk SEMUA genre | `SELECT ?genre (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:genre ?g . ?g rdfs:label ?genre } GROUP BY ?genre` | {'Action': 103, 'Science Fiction': 37, 'Adventure': 37, 'Mystery': 30, 'Thriller': 144, 'Comedy': 164, 'Romance': 62, 'Family': 28, 'Animation': 34, 'Crime': 71 | 19 baris hasil | Valid |
| 5 | Distribusi jumlah film untuk SEMUA bulan rilis | `SELECT ?bulan (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:datePublished ?d } GROUP BY (SUBSTR(STR(?d), 1, 7) AS ?bulan)` | {'2026-11': 25, '2026-10': 73, '2026-09': 94, '2026-08': 79, '2026-07': 78, '2026-06': 88, '2026-05': 63} | 7 baris hasil | Valid |
| 6 | Tampilkan film yang rilis pada bulan 2026-09 | `SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:datePublished ?d . FILTER(?d >= "2026-09-01"^^xsd:date && ?d <= "2026-09-30"^^xsd:date) }` | 94 | 94 | Valid |
| 7 | Tampilkan film berdurasi lebih dari 150 menit | `SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m cin:runtimeMinutes ?r . FILTER(?r > 150) }` | 45 | 45 | Valid |
| 8 | Tampilkan film yang disutradarai "Tyler Perry" (sutradara dengan film terbanyak) | `SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:director ?p . ?p schema:name "Tyler Perry" }` | 3 | 3 | Valid |
| 9 | Film paling populer (urut skor popularitas menurun) | `SELECT ?title WHERE { ?m a cin:Movie ; schema:name ?title ; cin:popularity ?p } ORDER BY DESC(?p) LIMIT 1` | Heart of the Beast | Heart of the Beast | Valid |
| 10 | Hitung pemeran film "Heart of the Beast" | `SELECT (COUNT(DISTINCT ?a) AS ?n) WHERE { ?m schema:name "Heart of the Beast" ; schema:actor ?a }` | 5 | 5 | Valid |
| 11 | Hitung peran (karakter) pada film "Heart of the Beast" | `SELECT (COUNT(DISTINCT ?r) AS ?n) WHERE { ?m schema:name "Heart of the Beast" ; cin:hasCastRole ?r . ?r cin:characterName ?c ; cin:byActor ?a }` | 5 | 5 | Valid |
| 12 | Aktor yang tampil di film terbanyak | `SELECT ?name (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:actor ?a . ?a schema:name ?name } GROUP BY ?a ?name ORDER BY DESC(?n) LIMIT 1` | (6, {'Giancarlo Esposito'}) | name=Giancarlo Esposito; n=6 | Valid |
| 13 | Tampilkan film bergenre "Comedy" sekaligus "Drama" (kombinasi paling umum) | `SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:genre ?g1 , ?g2 . ?g1 rdfs:label "Comedy"@en . ?g2 rdfs:label "Drama"@en }` | 50 | 50 | Valid |
| 14 | Hitung film yang tertaut ke Wikidata (owl:sameAs) | `SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m a cin:Movie ; owl:sameAs ?w . FILTER(STRSTARTS(STR(?w), "http://www.wikidata.org/entity/Q")) }` | 417 | 417 | Valid |
| 15 | Hitung film yang BELUM tertaut ke Wikidata | `SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m a cin:Movie . FILTER NOT EXISTS { ?m owl:sameAs ?w } }` | 83 | 83 | Valid |
| 16 | Hitung seluruh bioskop | `SELECT (COUNT(DISTINCT ?c) AS ?n) WHERE { ?c a cin:Cinema }` | 77 | 77 | Valid |
| 17 | Hitung bioskop dengan koordinat geografis valid | `SELECT (COUNT(DISTINCT ?c) AS ?n) WHERE { ?c a cin:Cinema ; schema:geo ?geo . ?geo a cin:GeoCoordinates ; schema:latitude ?lat ; schema:longitude ?lng }` | 77 | 77 | Valid |
| 18 | Distribusi bioskop berdasarkan jaringan | `SELECT ?chain (COUNT(DISTINCT ?c) AS ?n) WHERE { ?c a cin:Cinema ; cin:cinemaChain ?chain } GROUP BY ?chain` | {'CGV': 77} | chain=CGV; n=77 | Valid |
| 19 | Hitung seluruh auditorium | `SELECT (COUNT(DISTINCT ?a) AS ?n) WHERE { ?a a cin:Auditorium }` | 382 | 382 | Valid |
| 20 | Auditorium terhubung ke bioskop induknya | `SELECT (COUNT(DISTINCT ?a) AS ?n) WHERE { ?a a cin:Auditorium ; cin:partOfCinema ?cinema }` | 382 | 382 | Valid |
| 21 | Hitung seluruh jadwal tayang | `SELECT (COUNT(DISTINCT ?s) AS ?n) WHERE { ?s a cin:ScreeningEvent }` | 16221 | 16221 | Valid |
| 22 | Jadwal tayang terhubung ke film, bioskop, dan waktu mulai | `SELECT (COUNT(DISTINCT ?s) AS ?n) WHERE { ?s a cin:ScreeningEvent ; schema:workPresented ?movie ; cin:heldAtCinema ?cinema ; schema:startDate ?start }` | 16221 | 16221 | Valid |

## Tabel B. Hasil pengujian prototipe web server Jena Fuseki

| No | Aspek Pengujian | Test Case | Status | Keterangan |
|---|---|---|---|---|
| 1 | Infrastruktur | Menjalankan web server (ping) | Berhasil |  |
| 2 | Operasi triple store | Penambahan triple | Berhasil |  |
| 3 | Operasi triple store | Penghapusan triple | Berhasil |  |
| 4 | Operasi query | Melakukan query (hitung seluruh triple) | Berhasil | 412544 triple |
| 5 | Validasi query | Jumlah triple di Fuseki = jumlah triple berkas lokal | Berhasil | Fuseki 412544 vs lokal 412544 |
