<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Membaca jadwal tayang dari RDF melalui Apache Jena Fuseki.
 * Data jadwal tidak diambil dari tabel relasional atau JSON statis.
 */
class ScreeningRepository
{
    public function __construct(private SparqlClient $sparql) {}

    /**
     * Film yang memiliki setidaknya satu jadwal mulai dari sekarang sampai $days hari ke depan.
     * Hasil dikelompokkan per film agar satu film hanya tampil sekali.
     *
     * @return array<int, array<string, mixed>>
     */
    public function nowShowing(int $days = 7, ?string $city = null, ?int $genreId = null): array
    {
        $days = max(1, min($days, 14));
        $now = Carbon::now('UTC');
        $until = $now->copy()->addDays($days);
        $city = $city !== null && $city !== '' ? $city : null;
        $cacheKey = 'cinelog.screenings.now.v3.' . $now->format('YmdH') . '.' . $days . '.' . ($city ?? 'all') . '.' . ($genreId ?? 'all');

        return Cache::remember($cacheKey, 60, function () use ($now, $until, $city, $genreId) {
            $fromLiteral = $this->sparql->lit($now->format('Y-m-d\TH:i:sP'));
            $untilLiteral = $this->sparql->lit($until->format('Y-m-d\TH:i:sP'));
            $cityFilter = $city === null
                ? ''
                : 'FILTER(STR(?city) = ' . $this->sparql->lit($city) . ')';
            $genreFilter = $genreId === null
                ? ''
                : '?movie schema:genre ' . $this->sparql->iri($this->sparql->resource('genre', $genreId)) . ' .';

            $query = <<<SPARQL
            PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
            SELECT DISTINCT ?movie ?title ?movieUrl ?poster ?date ?vote WHERE {
              ?event a cin:ScreeningEvent ;
                     schema:workPresented ?movie ;
                     cin:heldAtCinema ?cinema ;
                     schema:startDate ?start ;
                     cin:screeningStatus "scheduled" .
              OPTIONAL { ?event schema:endDate ?end }
              FILTER (
                ((?start <= {$fromLiteral}^^xsd:dateTime) && BOUND(?end) && (?end >= {$fromLiteral}^^xsd:dateTime))
                || (?start >= {$fromLiteral}^^xsd:dateTime)
              )
              FILTER (?start <= {$untilLiteral}^^xsd:dateTime)

              ?cinema a cin:Cinema ; cin:city ?city .
              {$cityFilter}
              ?movie a cin:Movie ;
                    schema:name ?title ;
                    schema:url ?movieUrl ;
                    schema:datePublished ?date .
              {$genreFilter}
              OPTIONAL { ?movie schema:image ?poster }
              OPTIONAL { ?movie cin:tmdbVoteAverage ?vote }
            }
            ORDER BY ?title
            SPARQL;

            $rows = $this->sparql->select($this->sparql->prefixes() . $query);
            $movies = [];

            foreach ($rows as $row) {
                $slug = Str::after($row['movieUrl'], '/film/');
                if ($slug === '' || $slug === $row['movieUrl']) {
                    continue;
                }

                if (! isset($movies[$slug])) {
                    $movies[$slug] = [
                        'iri' => $row['movie'],
                        'title' => $row['title'],
                        'slug' => $slug,
                        'poster' => $row['poster'] ?? null,
                        'date' => $row['date'],
                        'vote' => isset($row['vote'])
                            ? (float) $row['vote']
                            : null,
                        'screenings' => [],
                    ];
                }

            }

            return array_values($movies);
        });
    }
    public function cities(): array
    {
        $query = <<<'SPARQL'
        SELECT DISTINCT ?city WHERE {
            ?cinema a cin:Cinema ;
                    cin:city ?city .
        }
        ORDER BY LCASE(STR(?city))
        SPARQL;

        return array_values(array_map(
            fn (array $row) => $row['city'],
            $this->sparql->select($this->sparql->prefixes() . $query)
        ));
    }

    /** @return array<int, array{id:int,label:string}> */
    public function genres(): array
    {
        $query = <<<'SPARQL'
        SELECT DISTINCT ?genre ?label WHERE {
          ?movie a cin:Movie ; schema:genre ?genre .
          ?genre rdfs:label ?label .
        }
        ORDER BY LCASE(STR(?label))
        SPARQL;

        return array_map(fn (array $row) => [
            'id' => (int) Str::afterLast($row['genre'], '/'),
            'label' => $row['label'],
        ], $this->sparql->select($this->sparql->prefixes() . $query));
    }

    /** @return array<int, array<string, mixed>> */
    public function scheduleForCinema(string $cinemaId, int $days = 7, ?string $movieSlug = null): array
    {
        $days = max(1, min($days, 14));
        $now = Carbon::now('UTC');
        $until = $now->copy()->addDays($days);
        $id = $this->sparql->lit($cinemaId);
        $from = $this->sparql->lit($now->format('Y-m-d\TH:i:sP'));
        $to = $this->sparql->lit($until->format('Y-m-d\TH:i:sP'));
        $movieFilter = $movieSlug === null ? '' : 'FILTER(?movieUrl = ' . $this->sparql->iri($this->sparql->filmUrl($movieSlug)) . ')';
        $query = <<<SPARQL
        PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
        SELECT ?event ?cinemaName ?city ?movie ?title ?movieUrl ?poster ?start ?end ?auditorium ?format ?subtitle ?price ?currency WHERE {
          ?cinema a cin:Cinema ; schema:identifier {$id} ; schema:name ?cinemaName ; cin:city ?city .
          ?event a cin:ScreeningEvent ; cin:heldAtCinema ?cinema ; schema:workPresented ?movie ;
                 schema:startDate ?start ; cin:screeningStatus "scheduled" .
          OPTIONAL { ?event schema:endDate ?end }
          FILTER (?start <= {$to}^^xsd:dateTime && ((?start >= {$from}^^xsd:dateTime) || (BOUND(?end) && ?end >= {$from}^^xsd:dateTime)))
          ?movie a cin:Movie ; schema:name ?title ; schema:url ?movieUrl .
          {$movieFilter}
          OPTIONAL { ?movie schema:image ?poster }
          OPTIONAL { ?event cin:screenedInAuditorium ?room . ?room schema:name ?auditorium }
          OPTIONAL { ?event schema:videoFormat ?format }
          OPTIONAL { ?event schema:subtitleLanguage ?subtitle }
          OPTIONAL { ?event schema:offers ?offer . ?offer schema:price ?price ; schema:priceCurrency ?currency }
        }
        ORDER BY ?start ?title
        SPARQL;

        return $this->sparql->select($this->sparql->prefixes() . $query);
    }

    /** @return array<int, array<string, string>> */
    public function cinemasForMovie(string $movieSlug, int $days = 7): array
    {
        $now = Carbon::now('UTC');
        $until = $now->copy()->addDays(max(1, min($days, 14)));
        $movie = $this->sparql->iri($this->sparql->filmUrl($movieSlug));
        $from = $this->sparql->lit($now->format('Y-m-d\TH:i:sP'));
        $to = $this->sparql->lit($until->format('Y-m-d\TH:i:sP'));
        $query = <<<SPARQL
        PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
        SELECT DISTINCT ?id ?name ?city WHERE {
          ?movie a cin:Movie ; schema:url {$movie} .
          ?event a cin:ScreeningEvent ; schema:workPresented ?movie ; cin:heldAtCinema ?cinema ; schema:startDate ?start ; cin:screeningStatus "scheduled" .
          OPTIONAL { ?event schema:endDate ?end }
          FILTER (?start <= {$to}^^xsd:dateTime && ((?start >= {$from}^^xsd:dateTime) || (BOUND(?end) && ?end >= {$from}^^xsd:dateTime)))
          ?cinema a cin:Cinema ; schema:identifier ?id ; schema:name ?name ; cin:city ?city .
        }
        ORDER BY LCASE(STR(?city)) LCASE(STR(?name))
        SPARQL;
        return $this->sparql->select($this->sparql->prefixes() . $query);
    }
}
