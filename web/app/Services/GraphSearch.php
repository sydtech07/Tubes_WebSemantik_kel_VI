<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Pencarian di knowledge graph CINÉLOG (Fuseki): film, orang (aktor, sutradara, penulis, komposer),
 * karakter, dan genre. Juga: saran saat mengetik, "mungkin maksud Anda", dan data halaman orang.
 *
 * Semua SPARQL untuk fitur ini ada di kelas ini; controller tidak menulis SPARQL.
 * Daftar nama (vocabulary) diambil SEKALI dari Fuseki lalu di-cache, jadi saran saat mengetik
 * tidak membebani Fuseki di setiap ketukan tombol.
 */
class GraphSearch
{
    /** Kata Indonesia -> nama genre TMDB (Inggris), agar "horor" menemukan "Horror". */
    public const GENRE_ALIAS = [
        'aksi' => 'action', 'petualangan' => 'adventure', 'animasi' => 'animation', 'komedi' => 'comedy',
        'kriminal' => 'crime', 'dokumenter' => 'documentary', 'keluarga' => 'family', 'fantasi' => 'fantasy',
        'sejarah' => 'history', 'horor' => 'horror', 'musik' => 'music', 'misteri' => 'mystery',
        'romantis' => 'romance', 'romansa' => 'romance', 'fiksi ilmiah' => 'science fiction',
        'sci-fi' => 'science fiction', 'film tv' => 'tv movie', 'perang' => 'war', 'barat' => 'western',
    ];
    private static \Transliterator|false|null $ascii = null;

    private const VOCAB_KEY = 'cinelog.vocab.v1';
    private const KIND_ORDER = ['film' => 0, 'orang' => 1, 'genre' => 2];

    public function __construct(private SparqlClient $sparql)
    {
    }

    /**
     * Peringkat kecocokan satu nama terhadap kata kunci (semua sudah dilipat/folded), atau null bila tidak cocok.
     * 0 = di awal nama, 1 = di awal sebuah kata, 2 = di tengah, 3 = tiap kata kunci adalah awal sebuah kata, tapi tidak berurutan.
     */
    private function rank(string $norm, string $comp, string $n, string $c, array $tokens): ?int
    {
        $pos = strpos($norm, $n);
        if ($pos !== false) {
            return $pos === 0 ? 0 : ($norm[$pos - 1] === ' ' ? 1 : 2);
        }
        $pos = strpos($comp, $c);
        if ($pos !== false) {
            return $pos === 0 ? 0 : 2;                  // "spiderman" cocok dengan "Spider-Man"
        }
        if (count($tokens) > 1) {
            $words = explode(' ', $norm);
            foreach ($tokens as $t) {
                $found = false;
                foreach ($words as $w) {
                    if (str_starts_with($w, $t)) {      // tiap kata kunci harus jadi awal sebuah kata
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    return null;
                }
            }

            return 3;                                   // "aktor 3" cocok dengan "Aktor Uji 3" (tapi tidak dengan "Aktor Uji 13")
        }

        return null;
    }

    // =================================================================== kata kunci
    /** Huruf kecil, spasi dirapikan, maksimal 60 karakter. */
    public function clean(string $q): string
    {
        return mb_substr(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $q) ?? '')), 0, 60);
    }

    /** Huruf kecil tanpa aksen dan tanda baca: "Zoé Bellas" -> "zoe bellas", "Spider-Man" -> "spider man". */
        /** Huruf kecil tanpa aksen dan tanda baca: "Zoé Bellas" -> "zoe bellas", "Spider-Man" -> "spider man". */
       /** Huruf kecil tanpa aksen dan tanda baca: "Zoé Bellas" -> "zoe bellas", "Spider-Man" -> "spider man". */
    public function fold(string $text): string
    {
        $text = mb_strtolower($text);
        // Transliterator dibuat SEKALI lalu dipakai ulang. Memanggil transliterator_transliterate() dengan teks
        // aturan di tiap nama membuat ulang objeknya setiap kali (15x lebih lambat untuk puluhan ribu nama).
        self::$ascii ??= class_exists(\Transliterator::class)
            ? (\Transliterator::create('Any-Latin; Latin-ASCII; Lower()') ?: false)
            : false;
        if (self::$ascii) {
            $text = self::$ascii->transliterate($text) ?: $text;
        }

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '');
    }
    /**
     * yang cocok bila aksen diabaikan (supaya "zoe" menemukan "Zoé"; CONTAINS di SPARQL peka aksen).
     */
    public function terms(string $q): array
    {
        $q = $this->clean($q);
        if ($q === '') {
            return [];
        }
        $terms = [$q];
        if (isset(self::GENRE_ALIAS[$q])) {
            $terms[] = self::GENRE_ALIAS[$q];
        }

        $folded = $this->fold($q);
        $compact = str_replace(' ', '', $folded);
        $tokens = $folded === '' ? [] : explode(' ', $folded);
        if (mb_strlen($compact) >= 2) {
            $extra = 0;
            foreach ($this->vocabulary() as [, $label, , $norm, , $comp]) {
                if ($this->rank($norm, $comp, $folded, $compact, $tokens) !== null && mb_stripos($label, $q) === false) {
                    $terms[] = mb_strtolower($label);
                    if (++$extra >= 8) {
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($terms));
    }

    // =================================================================== vocabulary (di-cache)
    /**
     * Semua judul film, nama orang, dan nama genre dalam satu daftar ringkas.
     * Elemen: [jenis, label, kunci, label_polos, popularitas, label_tanpa_spasi]
     * 'kunci' = slug (film) atau ID TMDB (orang, genre). Hasil kosong tidak di-cache.
     */
    public function vocabulary(): array
    {
        $cached = Cache::get(self::VOCAB_KEY);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $query = <<<'SPARQL'
        SELECT ?kind ?label ?id ?pop WHERE {
          { ?m a cin:Movie ; schema:name ?label ; schema:url ?id .
            OPTIONAL { ?m cin:popularity ?pop }
            BIND("film" AS ?kind) }
          UNION { ?p a cin:Person ; schema:name ?label ; cin:tmdbId ?id . BIND("orang" AS ?kind) }
          UNION { ?g a cin:Genre ; rdfs:label ?label ; cin:tmdbId ?id . BIND("genre" AS ?kind) }
        }
        SPARQL;

        $vocab = [];
        foreach ($this->sparql->select($this->sparql->prefixes() . $query) as $r) {
            $key = $r['kind'] === 'film' ? substr($r['id'], (int) strrpos($r['id'], '/') + 1) : $r['id'];
            $norm = $this->fold($r['label']);
            $vocab[] = [$r['kind'], $r['label'], $key, $norm, isset($r['pop']) ? (float) $r['pop'] : 0.0, str_replace(' ', '', $norm)];
        }

        if ($vocab !== []) {
            Cache::put(self::VOCAB_KEY, $vocab, 6 * 3600);
        }

        return $vocab;
    }

    // =================================================================== saran saat mengetik
    /**
     * Saran untuk kotak pencarian: sampai 4 film, 3 orang, 2 genre. Murni dari cache, tanpa ke Fuseki.
     *
     * @return array<int, array{kind: string, label: string, key: string}>
     */
    public function suggest(string $q): array
    {
        $n = $this->fold($q);
        $c = str_replace(' ', '', $n);
        if (mb_strlen($c) < 2) {
            return [];
        }

        $tokens = explode(' ', $n);
        $hits = ['film' => [], 'orang' => [], 'genre' => []];
        foreach ($this->vocabulary() as [$kind, $label, $key, $norm, $pop, $comp]) {
            $rank = $this->rank($norm, $comp, $n, $c, $tokens);
            if ($rank !== null) {
                $hits[$kind][] = [$rank, -$pop, $label, $key];
            }
        }

        $out = [];
        foreach (['film' => 4, 'orang' => 3, 'genre' => 2] as $kind => $max) {
            usort($hits[$kind], fn ($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
            foreach (array_slice($hits[$kind], 0, $max) as [, , $label, $key]) {
                $out[] = ['kind' => $kind, 'label' => $label, 'key' => (string) $key];
            }
        }

        return $out;
    }

    /**
     * "Mungkin maksud Anda": nama yang mirip (salah ketik 1 huruf per 4 huruf). Dipakai saat hasil pencarian kosong.
     *
     * @return array<int, array{kind: string, label: string, key: string}>
     */
    public function didYouMean(string $q, int $limit = 5): array
    {
        $c = str_replace(' ', '', $this->fold($q));
        $len = strlen($c);
        if ($len < 3 || $len > 60 || ! preg_match('/^[a-z0-9]+$/', $c)) {
            return [];
        }
        $tolerance = max(1, intdiv($len, 4));

        $found = [];
        foreach ($this->vocabulary() as [$kind, $label, $key, $norm, $pop, $comp]) {
            // bandingkan dengan awalan nama sepanjang kata kunci, lalu dengan tiap kata
            $best = levenshtein($c, substr($comp, 0, $len));
            if ($best > 0) {
                foreach (explode(' ', $norm) as $word) {
                    if (abs(strlen($word) - $len) <= $tolerance) {
                        $best = min($best, levenshtein($c, $word));
                    }
                }
            }
            if ($best <= $tolerance && $best < $len) {
                $found[] = [$best, self::KIND_ORDER[$kind], -$pop, $label, $kind, $key];
            }
        }

        usort($found, fn ($a, $b) => [$a[0], $a[1], $a[2], $a[3]] <=> [$b[0], $b[1], $b[2], $b[3]]);
        $out = [];
        $seen = [];
        foreach ($found as [, , , $label, $kind, $key]) {
            if (isset($seen[$kind . $label])) {
                continue;
            }
            $seen[$kind . $label] = true;
            $out[] = ['kind' => $kind, 'label' => $label, 'key' => (string) $key];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    // =================================================================== halaman hasil pencarian
    /**
     * Cari di film, orang, karakter, dan genre sekaligus. 'queries' berisi SPARQL yang dijalankan
     * (ditampilkan di halaman sebagai bahan demo).
     */
    public function search(string $q): array
    {
        $terms = $this->terms($q);
        $result = ['terms' => $terms, 'films' => [], 'people' => [], 'characters' => [], 'genres' => [], 'queries' => [], 'failed' => []];
        if ($terms === [] || mb_strlen($terms[0]) < 2) {
            return $result;
        }

        $values = implode(' ', array_map(fn (string $t) => $this->sparql->lit($t), $terms));
        $prefix = $this->sparql->prefixes();

        $run = function (string $name, string $query, string $needs) use (&$result, $prefix) {
            $query = $prefix . $query;
            $result['queries'][$name] = $query;

                       // Satu query yang gagal atau lambat (timeout) tidak boleh menggagalkan tiga query lainnya.
            try {
                // Query agregat tanpa data bisa mengembalikan satu baris kosong; baris tanpa variabel kunci dibuang.
                return array_values(array_filter($this->sparql->select($query), fn ($r) => isset($r[$needs])));
            } catch (\Throwable $e) {
                $result['failed'][] = $name;
                if (function_exists('report')) {
                    report($e);
                }

                return [];
            }
        };

        $films = <<<SPARQL
        SELECT DISTINCT ?m ?title ?url ?date ?poster ?pop ?vote WHERE {
          VALUES ?term { {$values} }
          ?m a cin:Movie ; schema:name ?title ; schema:url ?url ; schema:datePublished ?date .
          OPTIONAL { ?m schema:alternateName ?alt }
          OPTIONAL { ?m schema:image ?poster }
          OPTIONAL { ?m cin:popularity ?pop }
          OPTIONAL { ?m cin:tmdbVoteAverage ?vote }
          FILTER( CONTAINS(LCASE(STR(?title)), ?term) || CONTAINS(LCASE(STR(COALESCE(?alt, ""))), ?term) )
        }
        ORDER BY DESC(?pop) ?title ?m
        LIMIT 24
        SPARQL;

          // Daftar orang dicari tanpa menghitung film (cepat). Jumlah film dihitung terpisah di bawah,
        // hanya untuk orang yang cocok. Menggabungkan keduanya dengan OPTIONAL + property path
        // (a|b|c|d) membuat Fuseki macet (timeout) di data penuh.
        $people = <<<SPARQL
        SELECT DISTINCT ?p ?name ?photo ?id WHERE {
          VALUES ?term { {$values} }
          ?p a cin:Person ; schema:name ?name ; cin:tmdbId ?id .
          FILTER( CONTAINS(LCASE(STR(?name)), ?term) )
          OPTIONAL { ?p schema:image ?photo }
        }
        ORDER BY ?name
        LIMIT 100
        SPARQL;

        $characters = <<<SPARQL
        SELECT DISTINCT ?r ?char ?actor ?pid ?title ?url ?pop WHERE {
          VALUES ?term { {$values} }
          ?r a cin:CastRole ; cin:characterName ?char ; cin:byActor ?p .
          FILTER( CONTAINS(LCASE(STR(?char)), ?term) )
          ?m cin:hasCastRole ?r ; schema:name ?title ; schema:url ?url .
          ?p schema:name ?actor ; cin:tmdbId ?pid .
          OPTIONAL { ?m cin:popularity ?pop }
        }
        ORDER BY DESC(?pop) ?title ?char
        LIMIT 30
        SPARQL;

        $genres = <<<SPARQL
        SELECT ?g ?label ?id (COUNT(DISTINCT ?m) AS ?films) WHERE {
          VALUES ?term { {$values} }
          ?g a cin:Genre ; rdfs:label ?label ; cin:tmdbId ?id .
          FILTER( CONTAINS(LCASE(STR(?label)), ?term) )
          OPTIONAL { ?m schema:genre ?g }
        }
        GROUP BY ?g ?label ?id
        ORDER BY DESC(?films) ?label
        SPARQL;

        $result['films'] = array_map(fn (array $r) => [
            'iri' => $r['m'], 'title' => $r['title'], 'slug' => $this->tail($r['url']), 'date' => $r['date'],
            'poster' => $r['poster'] ?? null,
            'pop' => isset($r['pop']) ? (float) $r['pop'] : null, 'vote' => isset($r['vote']) ? (float) $r['vote'] : null,
        ], $run('film', $films, 'm'));

        $peopleRows = $run('orang', $people, 'p');
        $counts = $this->filmCounts(array_column($peopleRows, 'p'), $result);
        $people = array_map(fn (array $r) => [
            'iri' => $r['p'], 'id' => (int) $r['id'], 'name' => $r['name'], 'photo' => $r['photo'] ?? null,
            'films' => $counts[$r['p']] ?? 0,
        ], $peopleRows);
        usort($people, fn ($a, $b) => [$b['films'], $a['name']] <=> [$a['films'], $b['name']]);
        $result['people'] = array_slice($people, 0, 24);

        $result['characters'] = array_map(fn (array $r) => [
            'character' => $r['char'], 'actor' => $r['actor'], 'actor_id' => (int) $r['pid'],
            'title' => $r['title'], 'slug' => $this->tail($r['url']),
        ], $run('karakter', $characters, 'r'));

        $result['genres'] = array_map(fn (array $r) => [
            'iri' => $r['g'], 'id' => (int) $r['id'], 'label' => $r['label'], 'films' => (int) $r['films'],
        ], $run('genre', $genres, 'g'));

        return $result;
    }

    // =================================================================== halaman orang
    /** Profil + filmografi satu orang (di-cache 10 menit), atau null bila tidak ada. */
        /**
     * Jumlah film per orang (sebagai aktor, sutradara, penulis, atau komposer) untuk daftar IRI yang sudah pasti.
     * Memakai pola sederhana dengan ?p sudah terikat (VALUES), bukan property path, agar tiap pola cukup satu pencarian indeks.
     *
     * @param  array<int, string>  $iris
     * @return array<string, int> IRI => jumlah film
     */
    private function filmCounts(array $iris, array &$result): array
    {
        if ($iris === []) {
            return [];
        }
        $values = implode(' ', array_map(fn (string $i) => $this->sparql->iri($i), array_unique($iris)));
        $query = $this->sparql->prefixes() . <<<SPARQL
        SELECT ?p (COUNT(DISTINCT ?m) AS ?films) WHERE {
          VALUES ?p { {$values} }
          { ?m schema:actor ?p } UNION { ?m schema:director ?p } UNION { ?m schema:author ?p } UNION { ?m schema:musicBy ?p }
        }
        GROUP BY ?p
        SPARQL;
        $result['queries']['orang (jumlah film)'] = $query;

        $counts = [];
        try {
            foreach ($this->sparql->select($query) as $r) {
                if (isset($r['p'])) {
                    $counts[$r['p']] = (int) $r['films'];
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }
        }

        return $counts;
    }
    
    public function person(int $id): ?array
    {
        $key = "cinelog.person.v1.{$id}";
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $iri = $this->sparql->resource('person', $id);
        $p = $this->sparql->iri($iri);
        $prefix = $this->sparql->prefixes();

        $profile = $this->sparql->select($prefix . <<<SPARQL
        SELECT ?name ?gender ?photo ?dept WHERE {
          {$p} a cin:Person ; schema:name ?name .
          OPTIONAL { {$p} schema:gender ?gender }
          OPTIONAL { {$p} schema:image ?photo }
          OPTIONAL { {$p} cin:knownForDepartment ?dept }
        }
        LIMIT 1
        SPARQL)[0] ?? null;
        if ($profile === null) {
            return null;
        }

        $rows = $this->sparql->select($prefix . <<<SPARQL
        SELECT DISTINCT ?m ?title ?url ?date ?poster ?vote ?rel ?char WHERE {
          VALUES ?p { {$p} }
          ?m a cin:Movie ; schema:name ?title ; schema:url ?url ; schema:datePublished ?date .
          OPTIONAL { ?m schema:image ?poster }
          OPTIONAL { ?m cin:tmdbVoteAverage ?vote }
          {
            ?m schema:director ?p . BIND("Sutradara" AS ?rel)
          } UNION {
            ?m schema:author ?p . BIND("Penulis" AS ?rel)
          } UNION {
            ?m schema:musicBy ?p . BIND("Komposer" AS ?rel)
          } UNION {
            ?m cin:hasCastRole ?r . ?r cin:byActor ?p .
            OPTIONAL { ?r cin:characterName ?char }
            BIND("Aktor" AS ?rel)
          }
        }
        ORDER BY DESC(?date) ?title ?rel
        SPARQL);

        // satu film bisa muncul dengan beberapa peran (mis. aktor sekaligus sutradara): gabungkan per film
        $films = [];
        foreach ($rows as $r) {
            $slug = $this->tail($r['url']);
            $role = $r['rel'] === 'Aktor' && isset($r['char']) ? 'Aktor sebagai ' . $r['char'] : $r['rel'];
            $films[$slug] ??= [
                'iri' => $r['m'], 'title' => $r['title'], 'slug' => $slug, 'date' => $r['date'],
                'poster' => $r['poster'] ?? null, 'vote' => isset($r['vote']) ? (float) $r['vote'] : null, 'roles' => [],
            ];
            $films[$slug]['roles'][] = $role;
        }

        $person = [
            'iri' => $iri, 'id' => $id, 'name' => $profile['name'], 'gender' => $profile['gender'] ?? null,
            'photo' => $profile['photo'] ?? null, 'department' => $profile['dept'] ?? null,
            'films' => array_values($films),
        ];
        Cache::put($key, $person, 600);

        return $person;
    }

    private function tail(string $iri): string
    {
        return substr($iri, (int) strrpos($iri, '/') + 1);
    }
}