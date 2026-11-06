<?php

namespace App\Services;

/**
 * Pencarian TEMA: mencocokkan kata pada kata kunci (cin:hasKeyword) dan sinopsis film (schema:description).
 *
 * Contoh: "film zombie dari game" -> dua konsep: zombie, video game.
 * Film yang cocok dengan lebih banyak konsep tampil lebih atas; kata kunci dinilai lebih kuat daripada sinopsis.
 * Kamus sinonim di bawah menjembatani bahasa Indonesia dan Inggris (game = video game, hantu = ghost, dst.).
 *
 * Hanya dua query SPARQL sederhana (kata kunci, sinopsis); pengurutan dilakukan di PHP.
 */
class ThemeSearch
{
    /** Kata yang diabaikan (tidak membawa tema). */
    private const STOP = [
        'film', 'films', 'filmnya', 'movie', 'movies', 'yang', 'dari', 'dengan', 'tentang', 'berdasarkan', 'bertema',
        'tema', 'genre', 'cari', 'carikan', 'ada', 'based', 'on', 'a', 'an', 'the', 'of', 'about', 'with', 'and',
        'dan', 'atau', 'in', 'to', 'for', 'is', 'are', 'untuk', 'yg', 'di', 'ke', 'itu', 'ini',
    ];

    /**
     * Kata/frasa (sudah tanpa aksen dan huruf kecil) -> padanan yang dicari di kata kunci dan sinopsis.
     * Ini kamus buatan tangan: tambahkan baris baru kapan saja tanpa mengubah kode lain.
     */
    private const SYNONYMS = [
        'zombi' => ['zombi', 'undead'], 'zombie' => ['zombi', 'undead'], 'mayat hidup' => ['zombi', 'undead'],
        'game' => ['video game'], 'video game' => ['video game'], 'videogame' => ['video game'], 'permainan' => ['video game'],
        'dinosaurus' => ['dinosaur'], 'dinosaur' => ['dinosaur'], 'dino' => ['dinosaur'],
        'hantu' => ['ghost', 'haunted'], 'ghost' => ['ghost', 'haunted'], 'setan' => ['demon', 'devil', 'satan'],
        'iblis' => ['demon', 'devil'], 'vampir' => ['vampire'], 'manusia serigala' => ['werewolf'],
        'penyihir' => ['witch', 'wizard', 'sorcerer'], 'naga' => ['dragon'], 'robot' => ['robot', 'android'],
        'luar angkasa' => ['space', 'astronaut'], 'angkasa' => ['space'], 'antariksa' => ['space'],
        'cinta' => ['love', 'romance'], 'balas dendam' => ['revenge'], 'dendam' => ['revenge'],
        'pembunuh' => ['killer', 'murder', 'serial killer'], 'pembunuhan' => ['murder'], 'detektif' => ['detective'],
        'polisi' => ['police', 'cop'], 'mata mata' => ['spy'], 'pahlawan super' => ['superhero'],
        'superhero' => ['superhero'], 'monster' => ['monster', 'kaiju'], 'kisah nyata' => ['true story'],
        'cerita nyata' => ['true story'], 'true story' => ['true story'], 'novel' => ['novel'],
        'buku' => ['novel', 'book'], 'komik' => ['comic', 'superhero'], 'bertahan hidup' => ['survival'],
        'sekolah' => ['school', 'high school'], 'persahabatan' => ['friendship'], 'keluarga' => ['family'],
        'perang' => ['war'], 'kiamat' => ['apocalypse', 'post apocalyptic'], 'apocalypse' => ['apocalypse', 'post apocalyptic'],
        'hiu' => ['shark'], 'bajak laut' => ['pirate'], 'olahraga' => ['sport'], 'horor' => ['horror'],
        'seram' => ['horror', 'scary'], 'menyeramkan' => ['horror', 'scary'],
    ];

    private const MAX_CONCEPTS = 5;

    public function __construct(private SparqlClient $sparql, private GraphSearch $graph)
    {
    }

    /**
     * Pecah kalimat menjadi konsep. Tiap konsep: ['label' => kata asli, 'needles' => [padanan, ...]].
     *
     * @return array<int, array{label: string, needles: array<int, string>}>
     */
    public function concepts(string $q): array
    {
        $folded = $this->graph->fold($q);
        $words = $folded === '' ? [] : array_values(array_filter(explode(' ', $folded), fn ($w) => $w !== ''));
        $concepts = [];
        $seen = [];
        $add = function (string $label, array $needles) use (&$concepts, &$seen) {
            $key = implode('|', $needles);
            if (isset($seen[$key]) || count($concepts) >= self::MAX_CONCEPTS) {
                return;
            }
            $seen[$key] = true;
            $concepts[] = ['label' => $label, 'needles' => $needles];
        };

        for ($i = 0, $n = count($words); $i < $n; $i++) {
            $w = $words[$i];
            $pair = isset($words[$i + 1]) ? $w . ' ' . $words[$i + 1] : null;
            if ($pair !== null && isset(self::SYNONYMS[$pair])) {
                $add($pair, self::SYNONYMS[$pair]);
                $i++;

                continue;
            }
            if (in_array($w, self::STOP, true)) {
                continue;
            }
            $s = $this->singular($w);
            if (isset(self::SYNONYMS[$w]) || isset(self::SYNONYMS[$s])) {
                $add($w, self::SYNONYMS[$w] ?? self::SYNONYMS[$s]);
            } elseif (mb_strlen($s) >= 4) {
                $add($w, [$s]);   // kata biasa: minimal 4 huruf agar "man" tidak cocok dengan "human"
            }
        }

        return $concepts;
    }

    private function singular(string $w): string
    {
        return mb_strlen($w) > 4 && str_ends_with($w, 's') && ! preg_match('/(ss|us|is)$/', $w) ? mb_substr($w, 0, -1) : $w;
    }

    /** Pola regex: awal kata saja ("zombi" cocok dengan "zombie" dan "zombified", tapi "man" tidak cocok dengan "human"). */
    private function pattern(string $needle): string
    {
        $needle = trim(preg_replace('/[^a-z0-9]+/', ' ', $needle) ?? '');

        return '(^|[^a-z0-9])' . str_replace(' ', '[^a-z0-9]+', $needle);
    }

    /**
     * @return array{concepts: array, films: array, queries: array, failed: array, timings: array}
     */
    public function search(string $q): array
    {
        $out = ['concepts' => [], 'films' => [], 'queries' => [], 'failed' => [], 'timings' => []];
        $concepts = $this->concepts($q);
        $out['concepts'] = $concepts;
        if ($concepts === []) {
            return $out;
        }

        $pairs = [];
        foreach ($concepts as $idx => $c) {
            foreach ($c['needles'] as $needle) {
                $pairs[] = '(' . $idx . ' ' . $this->sparql->lit($this->pattern($needle)) . ')';
            }
        }
        $values = implode(' ', $pairs);
        $prefix = $this->sparql->prefixes();

        $byKeyword = <<<SPARQL
        SELECT DISTINCT ?m ?title ?url ?date ?poster ?pop ?vote ?c ?label WHERE {
          VALUES (?c ?pat) { {$values} }
          ?m a cin:Movie ; cin:hasKeyword ?k ; schema:name ?title ; schema:url ?url ; schema:datePublished ?date .
          ?k skos:prefLabel ?label .
          FILTER( REGEX(STR(?label), ?pat, "i") )
          OPTIONAL { ?m schema:image ?poster }
          OPTIONAL { ?m cin:popularity ?pop }
          OPTIONAL { ?m cin:tmdbVoteAverage ?vote }
        }
        ORDER BY DESC(?pop) ?m
        LIMIT 600
        SPARQL;

        $bySynopsis = <<<SPARQL
        SELECT DISTINCT ?m ?title ?url ?date ?poster ?pop ?vote ?c WHERE {
          VALUES (?c ?pat) { {$values} }
          ?m a cin:Movie ; schema:description ?txt ; schema:name ?title ; schema:url ?url ; schema:datePublished ?date .
          FILTER( REGEX(STR(?txt), ?pat, "i") )
          OPTIONAL { ?m schema:image ?poster }
          OPTIONAL { ?m cin:popularity ?pop }
          OPTIONAL { ?m cin:tmdbVoteAverage ?vote }
        }
        ORDER BY DESC(?pop) ?m
        LIMIT 600
        SPARQL;

        $run = function (string $name, string $query) use (&$out, $prefix): array {
            $out['queries'][$name] = $prefix . $query;
            $t = microtime(true);
            try {
                return $this->sparql->select($prefix . $query);
            } catch (\Throwable $e) {
                $out['failed'][] = $name;
                if (function_exists('report')) {
                    report($e);
                }

                return [];
            } finally {
                $out['timings'][$name] = (int) round((microtime(true) - $t) * 1000);
            }
        };

        $hits = [];   // IRI film => data + konsep yang cocok
        foreach (['tema: keyword' => [$byKeyword, 2], 'tema: sinopsis' => [$bySynopsis, 1]] as $name => [$query, $weight]) {
            foreach ($run($name, $query) as $r) {
                if (! isset($r['m'], $r['c'])) {
                    continue;
                }
                $iri = $r['m'];
                $hits[$iri] ??= [
                    'iri' => $iri, 'title' => $r['title'], 'slug' => basename($r['url']), 'date' => $r['date'],
                    'poster' => $r['poster'] ?? null,
                    'pop' => isset($r['pop']) ? (float) $r['pop'] : null, 'vote' => isset($r['vote']) ? (float) $r['vote'] : null,
                    'match' => [], 'labels' => [],
                ];
                $c = (int) $r['c'];
                $hits[$iri]['match'][$c] = max($hits[$iri]['match'][$c] ?? 0, $weight);
                if (isset($r['label'])) {
                    $hits[$iri]['labels'][$c][$r['label']] = true;
                }
            }
        }

        $films = array_map(function (array $f) use ($concepts) {
            $parts = [];
            foreach ($f['match'] as $c => $w) {
                $label = $concepts[$c]['label'];
                $parts[] = $w === 2
                    ? $label . ' (keyword: ' . implode(', ', array_slice(array_keys($f['labels'][$c] ?? []), 0, 2)) . ')'
                    : $label . ' (sinopsis)';
            }
            $f['matched'] = count($f['match']);
            $f['score'] = array_sum($f['match']);
            $f['note'] = 'Cocok ' . $f['matched'] . '/' . count($concepts) . ': ' . implode('; ', $parts);
            unset($f['match'], $f['labels']);

            return $f;
        }, array_values($hits));

        usort($films, fn ($a, $b) => [$b['matched'], $b['score'], $b['pop'] ?? 0, $a['title']]
            <=> [$a['matched'], $a['score'], $a['pop'] ?? 0, $b['title']]);
        $out['films'] = array_slice($films, 0, 24);

        return $out;
    }
}