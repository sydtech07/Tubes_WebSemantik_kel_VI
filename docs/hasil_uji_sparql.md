# Hasil uji validasi SPARQL (mode endpoint)

## Tabel A. Hasil pengujian uji kasus menggunakan query SPARQL

| No | Uji Kasus | Statement Kueri SPARQL | Hasil diharapkan (dari CSV) | Hasil aktual | Status |
|---|---|---|---|---|---|
| 1 | Hitung seluruh film | `SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m a cin:Movie }` | 500 | 500 | Valid |
| 2 | Hitung seluruh genre | `SELECT (COUNT(DISTINCT ?g) AS ?n) WHERE { ?g a cin:Genre }` | 19 | 19 | Valid |
| 3 | Tampilkan film bergenre "Drama" (genre paling banyak) | `SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:genre ?g . ?g rdfs:label "Drama"@en }` | 174 | 174 | Valid |
| 4 | Distribusi jumlah film untuk SEMUA genre | `SELECT ?genre (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:genre ?g . ?g rdfs:label ?genre } GROUP BY ?genre` | {'Action': 129, 'Science Fiction': 53, 'Adventure': 55, 'Horror': 84, 'Thriller': 155, 'Fantasy': 36, 'Comedy': 155, 'Mystery': 38, 'Drama': 174, 'Animation': 4 | 19 baris hasil | Valid |
| 5 | Distribusi jumlah film untuk SEMUA bulan rilis | `SELECT ?bulan (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:datePublished ?d } GROUP BY (SUBSTR(STR(?d), 1, 7) AS ?bulan)` | {'2026-07': 58, '2026-09': 58, '2026-08': 52, '2026-05': 57, '2026-06': 50, '2026-03': 53, '2026-04': 60, '2026-01': 45, '2026-02': 67} | 9 baris hasil | Valid |
| 6 | Tampilkan film yang rilis pada bulan 2026-02 | `SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:datePublished ?d . FILTER(?d >= "2026-02-01"^^xsd:date && ?d <= "2026-02-28"^^xsd:date) }` | 67 | 67 | Valid |
| 7 | Tampilkan film berdurasi lebih dari 150 menit | `SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m cin:runtimeMinutes ?r . FILTER(?r > 150) }` | 31 | 31 | Valid |
| 8 | Tampilkan film yang disutradarai "Andrew Stanton" (sutradara dengan film terbanyak) | `SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:director ?p . ?p schema:name "Andrew Stanton" }` | 2 | 2 | Valid |
| 9 | Film paling populer (urut skor popularitas menurun) | `SELECT ?title WHERE { ?m a cin:Movie ; schema:name ?title ; cin:popularity ?p } ORDER BY DESC(?p) LIMIT 1` | Spider-Man: Brand New Day | Spider-Man: Brand New Day | Valid |
| 10 | Hitung pemeran film "Spider-Man: Brand New Day" | `SELECT (COUNT(DISTINCT ?a) AS ?n) WHERE { ?m schema:name "Spider-Man: Brand New Day" ; schema:actor ?a }` | 15 | 15 | Valid |
| 11 | Hitung peran (karakter) pada film "Spider-Man: Brand New Day" | `SELECT (COUNT(DISTINCT ?r) AS ?n) WHERE { ?m schema:name "Spider-Man: Brand New Day" ; cin:hasCastRole ?r . ?r cin:characterName ?c ; cin:byActor ?a }` | 15 | 15 | Valid |
| 12 | Aktor yang tampil di film terbanyak | `SELECT ?name (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:actor ?a . ?a schema:name ?name } GROUP BY ?a ?name ORDER BY DESC(?n) LIMIT 1` | (6, {'Eric Bauza'}) | name=Eric Bauza; n=6 | Valid |
| 13 | Tampilkan film bergenre "Action" sekaligus "Thriller" (kombinasi paling umum) | `SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:genre ?g1 , ?g2 . ?g1 rdfs:label "Action"@en . ?g2 rdfs:label "Thriller"@en }` | 62 | 62 | Valid |
| 14 | Hitung film yang tertaut ke Wikidata (owl:sameAs) | `SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m a cin:Movie ; owl:sameAs ?w . FILTER(STRSTARTS(STR(?w), "http://www.wikidata.org/entity/Q")) }` | 451 | 451 | Valid |
| 15 | Hitung film yang BELUM tertaut ke Wikidata | `SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m a cin:Movie . FILTER NOT EXISTS { ?m owl:sameAs ?w } }` | 49 | 49 | Valid |

## Tabel B. Hasil pengujian prototipe web server Jena Fuseki

| No | Aspek Pengujian | Test Case | Status | Keterangan |
|---|---|---|---|---|
| 1 | Infrastruktur | Menjalankan web server (ping) | Berhasil |  |
| 2 | Operasi triple store | Penambahan triple | Berhasil |  |
| 3 | Operasi triple store | Penghapusan triple | Berhasil |  |
| 4 | Operasi query | Melakukan query (hitung seluruh triple) | Berhasil | 123591 triple |
| 5 | Validasi query | Jumlah triple di Fuseki = jumlah triple berkas lokal | Berhasil | Fuseki 123591 vs lokal 123591 |
