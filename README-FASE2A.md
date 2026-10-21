# CINÉLOG - Fase 2A: Ontologi, RDF, Fuseki, Uji SPARQL

Fase ini mengubah 500 film di `data/clean/*.csv` menjadi **dataset RDF buatan sendiri**, memuatnya ke
**Fuseki (SPARQL endpoint)**, lalu membuktikan lewat 15 uji kasus bahwa isinya benar.
Ini memenuhi syarat Web Semantik: dataset sendiri, SPARQL endpoint, query SPARQL, dan tautan ke dataset eksternal (Wikidata).

Semua perintah dijalankan dari **folder root proyek** (yang berisi `docker-compose.yml`), di PowerShell dengan venv aktif
(`pipeline\.venv\Scripts\Activate.ps1`; prompt menampilkan `(.venv)`).

## 0. Prasyarat

1. Bagian 1 selesai: `data\clean\*.csv` ada (500 film).
2. Container berjalan: `docker compose ps` menunjukkan `fuseki` berstatus running.
3. Fuseki hidup. Buka http://localhost:3030 di browser (halaman Fuseki muncul, dataset `/cinelog` terdaftar), atau:
   ```powershell
   Invoke-WebRequest 'http://localhost:3030/$/ping' -UseBasicParsing
   ```
   Harus menjawab StatusCode 200. Gunakan tanda kutip **tunggal** karena `$` punya arti khusus di PowerShell.

## 1. Pasang paket ini

1. Ekstrak zip `cinelog-fase2a.zip` **ke folder root proyek**. Jika Windows bertanya, pilih *merge* untuk folder dan
   *replace* untuk `pipeline\requirements.txt`.
2. Pasang pustaka baru (rdflib):
   ```powershell
   python -m pip install -r pipeline\requirements.txt
   ```

## 2. Bangun dataset RDF

```powershell
python pipeline\04_build_rdf.py
```
Hasil: `data\rdf\cinelog-data.ttl` dan `data\rdf\rdf_report.json`. Angka yang harus cocok dengan `report.json` Bagian 1:
`film` = 500, `orang` = 7807, `genre` = 19, dan `film_tertaut_wikidata` kira-kira 451 (90,2% dari 500).
Jumlah triple kira-kira 100 ribu. Menambahkan `--rdfxml` menghasilkan juga `cinelog-data.rdf` (format RDF/XML).

## 3. Muat ke Fuseki

```powershell
python pipeline\05_load_fuseki.py
```
Hasil yang benar berakhir dengan `Verifikasi berhasil: jumlah triple cocok.` Aman diulang (data lama diganti, bukan ditambah).

## 4. Uji validasi SPARQL

```powershell
python pipeline\06_validate_sparql.py --endpoint http://localhost:3030/cinelog
```
Harapannya: `15/15 uji kasus valid, infrastruktur 5/5 berhasil`. Hasilnya tersimpan di **`docs\hasil_uji_sparql.md`**:
- **Tabel A**: uji kasus + query SPARQL + hasil yang diharapkan (dihitung dari CSV, bukan dari RDF) + hasil aktual + status.
- **Tabel B**: uji infrastruktur Fuseki (ping, tambah triple, hapus triple, query, perbandingan jumlah triple).

Kedua tabel mengikuti bentuk "Contoh Validasi" di slide dosen; tempel ke bab Hasil dan Pembahasan.
Tanpa Fuseki pun bisa diuji lokal: `python pipeline\06_validate_sparql.py --ttl data\rdf\cinelog-data.ttl`.

## 5. Coba sendiri di antarmuka Fuseki (bahan screenshot paper)

Buka http://localhost:3030, pilih dataset **/cinelog**, tab **query**, tempel:

```sparql
PREFIX schema: <https://schema.org/>
PREFIX cin:    <http://localhost:8000/ontology#>
SELECT ?judul ?tanggal ?menit
WHERE {
  ?film a cin:Movie ; schema:name ?judul ; schema:datePublished ?tanggal ; cin:runtimeMinutes ?menit .
  FILTER(?menit > 150)
}
ORDER BY DESC(?menit)
LIMIT 10
```

## 6. Integrasi dataset eksternal (Wikidata)

Setiap film yang punya ID Wikidata ditautkan lewat `owl:sameAs` ke `http://www.wikidata.org/entity/Q...`.
File `sparql\federated_wikidata.rq` memakai `SERVICE` untuk mengambil negara asal dan box office dari Wikidata,
data yang tidak ada di dataset kita. Jalankan isinya di tab query Fuseki.
**Belum teruji** dari lingkungan pengembang (jaringan terbatas). Jika gagal, kirim pesan errornya;
integrasi Wikidata di aplikasi Laravel akan dilakukan langsung dari kode PHP sehingga tidak bergantung pada ini.

## 7. Periksa ontologi di Protégé (opsional, disebut di slide dosen)

Pasang Protégé, lalu File > Open `ontology\cinelog.ttl`. Kelas: `Movie`, `Person`, `Genre`, `CastRole`.
Properti sendiri: `tmdbId`, `runtimeMinutes`, `popularity`, `hasCastRole`, `byActor`, `characterName`, dan lain-lain.
Properti lain memakai schema.org (`name`, `datePublished`, `genre`, `actor`, `director`).

## Model data singkat

| Entitas | IRI | Properti utama |
|---|---|---|
| Film | `http://localhost:8000/resource/movie/{tmdb_id}` | schema:name, datePublished, duration, description (@en/@id), genre, actor, director, author, musicBy, image, owl:sameAs (Wikidata) |
| Orang | `.../resource/person/{tmdb_id}` | schema:name, gender, image |
| Genre | `.../resource/genre/{tmdb_id}` | rdfs:label (@en), skos:prefLabel |
| Peran | `.../resource/cast/{film}-{orang}-{hash}` | cin:byActor, cin:characterName, cin:billingOrder |

Halaman manusia untuk film: `http://localhost:8000/film/{slug}` (tercatat sebagai `schema:url`).
Rute ini wajib ada di aplikasi Laravel pada Fase 2B.
Rating dan review pengguna **tidak** dimasukkan ke RDF karena berubah terus; nilainya ditampilkan di halaman lewat RDFa.

## Masalah umum

| Gejala | Solusi |
|---|---|
| `Tidak bisa menghubungi Fuseki` | `docker compose ps`; jalankan `docker compose up -d`; cek `docker compose logs fuseki` |
| `Unggah data gagal ... HTTP 404/403` | Fuseki harus dijalankan dengan `--update` dan dataset bernama `/cinelog`. Kirim keluaran lengkap skrip beserta `docker compose logs fuseki` |
| `ModuleNotFoundError: rdflib` | `python -m pip install -r pipeline\requirements.txt` (venv aktif) |
| `... tidak ada. Jalankan 02_clean.py dulu` | Folder `data\clean` kosong; jalankan ulang langkah Bagian 1 |
| Ada uji "Tidak valid" | Buka `docs\hasil_uji_sparql.md`; baca kolom diharapkan vs aktual. Biasanya data belum dimuat ulang setelah CSV berubah: ulangi langkah 2 dan 3 |
