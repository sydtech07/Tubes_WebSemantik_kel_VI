<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Semua query SPARQL untuk katalog dan halaman film ada di sini.
 * Controller tidak boleh menulis SPARQL sendiri; cukup memanggil kelas ini.
 */
class MovieRepository
{
    /** Kunci ?sort => klausa ORDER BY. Hanya nilai di sini yang pernah masuk ke query (whitelist). */
    public const SORTS = [
        'populer' => 'DESC(?pop) ?m',
        'terbaru' => 'DESC(?date) ?m',
        'skor'    => 'DESC(?vote) DESC(?pop) ?m',
        'judul'   => 'ASC(LCASE(STR(?title))) ?m',
    ];

    public function __construct(private SparqlClient $sparql)
    {
    }

    /**
     * Satu halaman katalog.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function catalog(string $q, ?int $genreId, string $sort, int $page, int $perPage): array
    {
        $where  = $this->filters($q, $genreId);
        $prefix = $this->sparql->prefixes();

        $total = (int) ($this->sparql->select(
            $prefix . "SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE {\n{$where}\n}"
        )[0]['n'] ?? 0);

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $order  = self::SORTS[$sort] ?? self::SORTS['populer'];
        $offset = (max(1, $page) - 1) * $perPage;

        $query = <<<SPARQL
        SELECT ?m ?title ?url ?date ?poster ?pop ?vote WHERE {
        {$where}
        OPTIONAL { ?m schema:image ?poster }
        OPTIONAL { ?m cin:popularity ?pop }
        OPTIONAL { ?m cin:tmdbVoteAverage ?vote }
        }
        ORDER BY {$order}
        LIMIT {$perPage} OFFSET {$offset}
        SPARQL;

        $items = array_map(fn (array $r) => [
            'iri'    => $r['m'],
            'title'  => $r['title'],
            'slug'   => Str::after($r['url'], '/film/'),
            'date'   => $r['date'],
            'poster' => $r['poster'] ?? null,
            'pop'    => isset($r['pop']) ? (float) $r['pop'] : null,
            'vote'   => isset($r['vote']) ? (float) $r['vote'] : null,
        ], $this->sparql->select($prefix . $query));

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Daftar genre beserta jumlah film (untuk chip filter). Di-cache 1 jam,
     * tetapi hasil KOSONG tidak pernah di-cache (agar chip langsung muncul setelah data dimuat ke Fuseki).
     *
     * @return array<int, array{id: int, label: string, n: int}>
     */
    public function genres(): array
    {
        $cached = Cache::get('cinelog.genres.v1');
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $query = <<<'SPARQL'
        SELECT ?g ?label (COUNT(DISTINCT ?m) AS ?n) WHERE {
          ?m a cin:Movie ; schema:genre ?g .
          ?g rdfs:label ?label .
        }
        GROUP BY ?g ?label
        ORDER BY ?label
        SPARQL;

        $genres = array_map(fn (array $r) => [
            'id'    => (int) Str::afterLast($r['g'], '/'),
            'label' => $r['label'],
            'n'     => (int) $r['n'],
        ], $this->sparql->select($this->sparql->prefixes() . $query));

        if ($genres !== []) {
            Cache::put('cinelog.genres.v1', $genres, 3600);
        }

        return $genres;
    }

    /**
     * Film unggulan untuk hero beranda: paling populer yang punya gambar latar. Di-cache 10 menit
     * (hasil kosong tidak di-cache).
     *
     * @return array<int, array<string, mixed>>
     */
    public function featured(int $limit = 5): array
    {
        $limit  = max(1, min($limit, 10));
        $cached = Cache::get("cinelog.featured.v1.{$limit}");
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $query = <<<SPARQL
        SELECT ?m ?title ?url ?date ?backdrop ?vote ?runtime ?descId ?descEn WHERE {
          ?m a cin:Movie ; schema:name ?title ; schema:url ?url ; schema:datePublished ?date ;
             cin:backdropImage ?backdrop ; cin:popularity ?pop .
          OPTIONAL { ?m cin:tmdbVoteAverage ?vote }
          OPTIONAL { ?m cin:runtimeMinutes ?runtime }
          OPTIONAL { ?m schema:description ?descId . FILTER(LANG(?descId) = "id") }
          OPTIONAL { ?m schema:description ?descEn . FILTER(LANG(?descEn) = "en") }
        }
        ORDER BY DESC(?pop) ?m
        LIMIT {$limit}
        SPARQL;

        $items = array_map(fn (array $r) => [
            'title'       => $r['title'],
            'slug'        => Str::after($r['url'], '/film/'),
            'date'        => $r['date'],
            'backdrop'    => $r['backdrop'],
            'vote'        => isset($r['vote']) ? (float) $r['vote'] : null,
            'runtime'     => isset($r['runtime']) ? (int) $r['runtime'] : null,
            'description' => $r['descId'] ?? $r['descEn'] ?? '',
        ], $this->sparql->select($this->sparql->prefixes() . $query));

        if ($items !== []) {
            Cache::put("cinelog.featured.v1.{$limit}", $items, 600);
        }

        return $items;
    }

    /**
     * Seluruh data satu film untuk halaman detail, atau null bila tidak ada di Fuseki.
     * Slug dipetakan ke film lewat schema:url (nilai ini ditulis oleh pipeline/04_build_rdf.py).
     */
    public function detail(string $slug): ?array
    {
        $url    = $this->sparql->filmUrl($slug);
        $u      = $this->sparql->iri($url);
        $prefix = $this->sparql->prefixes();

        $mainQuery = <<<SPARQL
        SELECT ?m ?title ?alt ?date ?runtime ?descId ?descEn ?tagline ?poster ?backdrop
               ?lang ?imdb ?wd ?vote ?votes ?budget ?revenue
        WHERE {
          ?m schema:url {$u} ; a cin:Movie ; schema:name ?title ; schema:datePublished ?date .
          OPTIONAL { ?m schema:alternateName ?alt }
          OPTIONAL { ?m cin:runtimeMinutes ?runtime }
          OPTIONAL { ?m schema:description ?descId . FILTER(LANG(?descId) = "id") }
          OPTIONAL { ?m schema:description ?descEn . FILTER(LANG(?descEn) = "en") }
          OPTIONAL { ?m schema:slogan ?tagline }
          OPTIONAL { ?m schema:image ?poster }
          OPTIONAL { ?m cin:backdropImage ?backdrop }
          OPTIONAL { ?m cin:originalLanguage ?lang }
          OPTIONAL { ?m cin:imdbId ?imdb }
          OPTIONAL { ?m owl:sameAs ?wd . FILTER(STRSTARTS(STR(?wd), "http://www.wikidata.org/entity/Q")) }
          OPTIONAL { ?m cin:tmdbVoteAverage ?vote }
          OPTIONAL { ?m cin:tmdbVoteCount ?votes }
          OPTIONAL { ?m cin:budget ?budget }
          OPTIONAL { ?m cin:revenue ?revenue }
        }
        LIMIT 1
        SPARQL;

        $main = $this->sparql->select($prefix . $mainQuery)[0] ?? null;
        if ($main === null) {
            return null;
        }

        $genreQuery = <<<SPARQL
        SELECT ?g ?label WHERE {
          ?m schema:url {$u} ; schema:genre ?g .
          ?g rdfs:label ?label .
        }
        ORDER BY ?label
        SPARQL;

        $crewQuery = <<<SPARQL
        SELECT ?role ?p ?name WHERE {
          ?m schema:url {$u} .
          { ?m schema:director ?p . BIND("director" AS ?role) }
          UNION { ?m schema:author ?p . BIND("writer" AS ?role) }
          UNION { ?m schema:musicBy ?p . BIND("composer" AS ?role) }
          ?p schema:name ?name .
        }
        ORDER BY ?role ?name
        SPARQL;

        $castQuery = <<<SPARQL
        SELECT ?p ?name ?char ?ord ?photo WHERE {
          ?m schema:url {$u} ; cin:hasCastRole ?r .
          ?r cin:byActor ?p .
          ?p schema:name ?name .
          OPTIONAL { ?r cin:characterName ?char }
          OPTIONAL { ?r cin:billingOrder ?ord }
          OPTIONAL { ?p schema:image ?photo }
        }
        ORDER BY ?ord
        LIMIT 30
        SPARQL;

        $genres = $this->sparql->select($prefix . $genreQuery);
        $crew   = $this->sparql->select($prefix . $crewQuery);
        $cast   = $this->sparql->select($prefix . $castQuery);

        $person = fn (array $r) => ['iri' => $r['p'], 'name' => $r['name']];
        $byRole = fn (string $role) => array_values(
            array_map($person, array_filter($crew, fn ($r) => $r['role'] === $role))
        );

        return [
            'iri'         => $main['m'],
            'url'         => $url,
            'title'       => $main['title'],
            'altTitle'    => $main['alt'] ?? null,
            'date'        => $main['date'],
            'runtime'     => isset($main['runtime']) ? (int) $main['runtime'] : null,
            'descId'      => $main['descId'] ?? null,
            'descEn'      => $main['descEn'] ?? null,
            'description' => $main['descId'] ?? $main['descEn'] ?? null,
            'tagline'     => $main['tagline'] ?? null,
            'poster'      => $main['poster'] ?? null,
            'backdrop'    => $main['backdrop'] ?? null,
            'lang'        => $main['lang'] ?? null,
            'imdb'        => $main['imdb'] ?? null,
            'wikidata'    => $main['wd'] ?? null,
            'vote'        => $main['vote'] ?? null,   // string asli dari RDF (mis. "8.861") agar RDFa cocok persis
            'votes'       => isset($main['votes']) ? (int) $main['votes'] : null,
            'budget'      => isset($main['budget']) ? (int) $main['budget'] : null,
            'revenue'     => isset($main['revenue']) ? (int) $main['revenue'] : null,
            'genres'      => array_map(fn (array $r) => [
                'iri'   => $r['g'],
                'id'    => (int) Str::afterLast($r['g'], '/'),
                'label' => $r['label'],
            ], $genres),
            'directors'   => $byRole('director'),
            'writers'     => $byRole('writer'),
            'composers'   => $byRole('composer'),
            'cast'        => array_map(fn (array $r) => [
                'iri'       => $r['p'],
                'name'      => $r['name'],
                'character' => $r['char'] ?? null,
                'order'     => isset($r['ord']) ? (int) $r['ord'] : null,
                'photo'     => $r['photo'] ?? null,
            ], $cast),
        ];
    }

    /** Pola graf untuk katalog: judul/alt-judul mengandung kata kunci, dan (opsional) genre tertentu. */
    private function filters(string $q, ?int $genreId): string
    {
        $lines = ['?m a cin:Movie ; schema:name ?title ; schema:url ?url ; schema:datePublished ?date .'];

        if ($genreId) {
            $lines[] = '?m schema:genre ' . $this->sparql->iri($this->sparql->resource('genre', $genreId)) . ' .';
        }

        $term = mb_strtolower(trim($q));
        if ($term !== '') {
            $t = $this->sparql->lit($term);
            $lines[] = 'OPTIONAL { ?m schema:alternateName ?alt }';
            $lines[] = "FILTER(CONTAINS(LCASE(STR(?title)), {$t}) || CONTAINS(LCASE(STR(?alt)), {$t}))";
        }

        return implode("\n", $lines);
    }
}
