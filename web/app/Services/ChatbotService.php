<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;

class ChatbotService
{
    private const GENRES = [
        'action', 'adventure', 'animation', 'comedy', 'crime', 'documentary', 'drama', 'family',
        'fantasy', 'history', 'horror', 'music', 'mystery', 'romance', 'science fiction', 'tv movie',
        'thriller', 'war', 'western',
    ];

    public function __construct(private ?SparqlClient $sparql = null) {}

    public function askMovieBot(string $userQuery): string
    {
        $userQuery = trim(preg_replace('/\s+/u', ' ', $userQuery) ?? '');
        if ($userQuery === '') {
            return 'Tulis pertanyaan tentang film, orang, genre, atau rekomendasi.';
        }
        $apiKey = config('services.gemini.api_key');
        $context = $this->movieContext($userQuery);

        if (! $apiKey) {
            return $this->semanticFallback($context);
        }

        $model = config('services.gemini.model');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
        if ($context['error']) {
            return 'Maaf, saya tidak dapat membaca knowledge graph CINÉLOG saat ini. Coba lagi sebentar lagi.';
        }
        $history = $this->history((string) session()->getId());
        $prompt = "Kamu adalah asisten film CINÉLOG. Jawab dalam bahasa Indonesia dengan ringkas dan jujur.\n"
            ."Jawab pertanyaan film berdasarkan DATA knowledge graph CINÉLOG di bawah ini. Data itu hasil pencarian untuk pertanyaan pengguna, termasuk rekomendasi berdasarkan genre, sinopsis, kata kunci, dan skor TMDB.\n"
            ."Jangan mengarang judul, skor, sinopsis, atau fakta yang tidak ada di data. Jika data relevan tersedia, gunakan data tersebut secara langsung. Jika tidak ada, katakan bahwa dataset belum punya data yang cocok. Skor dan jumlah suara adalah milik TMDB.\n"
            ."DATA RDF/FUSEKI:\n{$context['text']}\n"
            ."RIWAYAT SINGKAT:\n{$history}\n"
            ."PERTANYAAN PENGGUNA:\n{$userQuery}";

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->timeout(20)
                ->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Gemini chatbot request failed.', ['status' => $response->status()]);

                return $this->semanticFallback($context);
            }

            $reply = data_get($response->json(), 'candidates.0.content.parts.0.text');
            if (! is_string($reply) || trim($reply) === '') {
                Log::warning('Gemini chatbot returned no reply.');

                return $this->semanticFallback($context);
            }

            $this->saveHistory((string) session()->getId(), $userQuery, $reply);

            return $reply;
        } catch (ConnectionException $exception) {
            Log::warning('Gemini chatbot connection failed.', ['message' => $exception->getMessage()]);

            return $this->semanticFallback($context);
        }
    }

    /**
     * Retrieve candidate movies directly from the project dataset instead of relying
     * on exact title/name matches from the site search.
     *
     * @return array{text: string, movies: array<int, array<string, string>>, error: bool}
     */
    private function movieContext(string $query): array
    {
        try {
            $sparql = $this->sparql ?? app(SparqlClient::class);
            $genreLabels = $this->genresIn($query);
            $recommendation = preg_match('/\b(rekomend\w*|saran\w*|tonton\w*|watch|terbaik)\b/iu', $query) === 1;
            $terms = $this->searchTerms($query);
            $genreFilter = null;

            if ($genreLabels !== []) {
                $labels = implode(', ', array_map(fn (string $label) => $sparql->lit($label), $genreLabels));
                $genreFilter = 'LCASE(STR(?genreLabel)) IN ('.mb_strtolower($labels).')';
            }

            $genreTerms = [];
            $aliases = GraphSearch::GENRE_ALIAS;
            foreach (self::GENRES as $genre) {
                $aliases[$genre] = $genre;
            }
            foreach ($aliases as $alias => $genre) {
                if (in_array($genre, $genreLabels, true)) {
                    $genreTerms = array_merge($genreTerms, explode(' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', $alias) ?? ''));
                }
            }
            $genreTerms = array_values(array_filter(array_unique($genreTerms)));
            $terms = array_values(array_filter($terms, fn (string $term) => ! in_array($term, $genreTerms, true)));

            $termMatches = [];
            foreach ($terms as $term) {
                $literal = $sparql->lit($term);
                $termMatches[] = '(CONTAINS(LCASE(STR(?title)), '.$literal.')'
                    .' || CONTAINS(LCASE(STR(COALESCE(?description, ""))), '.$literal.')'
                    .' || EXISTS { ?m cin:hasKeyword ?keyword . ?keyword rdfs:label ?keywordLabel . FILTER(CONTAINS(LCASE(STR(?keywordLabel)), '.$literal.')) }'
                    .' || EXISTS { ?m schema:actor ?person . ?person schema:name ?personName . FILTER(CONTAINS(LCASE(STR(?personName)), '.$literal.')) }'
                    .' || EXISTS { ?m schema:director ?director . ?director schema:name ?directorName . FILTER(CONTAINS(LCASE(STR(?directorName)), '.$literal.')) })';
            }

            $textFilter = $termMatches === [] ? null : '('.implode(' || ', $termMatches).')';
            $filters = array_values(array_filter([$genreFilter, $textFilter]));
            if ($filters === [] && $recommendation) {
                $filters[] = 'true';
            }
            $queryFilter = $filters === [] ? 'false' : implode("\n          && ", $filters);
            $relevance = $termMatches === [] ? '0' : implode(' + ', array_map(
                fn (string $match) => 'MAX(IF('.$match.', 1, 0))',
                $termMatches
            ));
            $sparqlQuery = $sparql->prefixes().<<<SPARQL
            SELECT ?m ?title ?url
                   (SAMPLE(?description) AS ?overview)
                   (SAMPLE(?vote) AS ?rating)
                   (SAMPLE(?voteCount) AS ?votes)
                   (MAX(?popularity) AS ?pop)
                   ({$relevance} AS ?relevance)
                   (GROUP_CONCAT(DISTINCT STR(?genreLabel); separator=", ") AS ?genres)
            WHERE {
              ?m a cin:Movie ; schema:name ?title ; schema:url ?url .
              OPTIONAL { ?m schema:description ?description }
              OPTIONAL { ?m cin:tmdbVoteAverage ?vote }
              OPTIONAL { ?m cin:tmdbVoteCount ?voteCount }
              OPTIONAL { ?m cin:popularity ?popularity }
              OPTIONAL { ?m schema:genre ?genre . ?genre rdfs:label ?genreLabel }
              FILTER (
                {$queryFilter}
              )
            }
            GROUP BY ?m ?title ?url
            ORDER BY DESC(?relevance) DESC(?pop) DESC(?rating) DESC(?votes)
            LIMIT 8
            SPARQL;
            $movies = $sparql->select($sparqlQuery);
            $movies = array_map(fn (array $movie) => [
                'title' => $movie['title'],
                'url' => $movie['url'],
                'genres' => $movie['genres'] ?? '',
                'rating' => $movie['rating'] ?? '',
                'votes' => $movie['votes'] ?? '',
                'overview' => Str::limit($movie['overview'] ?? '', 350, '...'),
            ], $movies);

            $lines = array_map(function (array $movie) {
                $details = ['Genre: '.($movie['genres'] ?: 'tidak tercatat')];
                if ($movie['rating'] !== '') {
                    $details[] = 'Skor TMDB: '.number_format((float) $movie['rating'], 1).'/10';
                }
                if ($movie['votes'] !== '') {
                    $details[] = 'Jumlah suara TMDB: '.$movie['votes'];
                }
                if ($movie['overview'] !== '') {
                    $details[] = 'Sinopsis: '.$movie['overview'];
                }

                return 'Film: '.$movie['title'].' | '.implode(' | ', $details).' | URL: '.$movie['url'];
            }, $movies);

            return [
                'text' => $lines === [] ? 'Tidak ada film yang cocok di dataset CINÉLOG.' : implode("\n", $lines),
                'movies' => $movies,
                'error' => false,
            ];
        } catch (\Throwable $e) {
            Log::warning('Semantic chatbot dataset retrieval failed.', ['message' => $e->getMessage()]);

            return ['text' => '', 'movies' => [], 'error' => true];
        }
    }

    /** @return array<int, string> */
    private function genresIn(string $query): array
    {
        $normalized = ' '.mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $query) ?? '').' ';
        $labels = [];
        $aliases = GraphSearch::GENRE_ALIAS;

        foreach (self::GENRES as $genre) {
            $aliases[$genre] = $genre;
        }
        $aliases['sci fi'] = 'science fiction';
        $aliases['fiksi sains'] = 'science fiction';
        $aliases['thriller'] = 'thriller';

        foreach ($aliases as $alias => $genre) {
            $normalizedAlias = ' '.mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $alias) ?? '').' ';
            if ($normalizedAlias !== '  ' && str_contains($normalized, $normalizedAlias)) {
                $labels[] = $genre;
            }
        }

        return array_values(array_unique($labels));
    }

    /** @return array<int, string> */
    private function searchTerms(string $query): array
    {
        $normalized = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $query) ?? '');
        $stopWords = [
            'ada', 'aktor', 'aktris', 'apa', 'atau', 'bagus', 'best', 'buat', 'dan', 'dengan', 'di', 'dong', 'film',
            'films', 'for', 'genre', 'in', 'info', 'ke', 'kasih', 'minta', 'movie', 'movies', 'of', 'sutradara',
            'paling', 'rekomendasi', 'rekomendasikan', 'saran', 'saranin', 'saya', 'seru', 'tentang',
            'terbaik', 'the', 'tahun', 'tolong', 'tonton', 'untuk', 'yang',
        ];
        $terms = array_values(array_unique(array_filter(
            explode(' ', $normalized),
            fn (string $term) => mb_strlen($term) >= 3 && ! in_array($term, $stopWords, true) && ! preg_match('/^\d+$/', $term)
        )));

        return array_slice($terms, 0, 6);
    }

    /**
     * @param  array{text: string, movies: array<int, array<string, string>>, error: bool}  $context
     */
    private function semanticFallback(array $context): string
    {
        if ($context['error']) {
            return 'Maaf, saya tidak dapat membaca knowledge graph CINÉLOG saat ini. Coba lagi sebentar lagi.';
        }
        if ($context['movies'] === []) {
            return 'Saya belum menemukan film yang cocok di dataset CINÉLOG. Coba sebutkan judul, aktor, kata kunci cerita, atau genre seperti action, komedi, dan horor.';
        }

        $recommendations = array_map(function (array $movie) {
            $details = [];
            if ($movie['genres'] !== '') {
                $details[] = $movie['genres'];
            }
            if ($movie['rating'] !== '') {
                $details[] = 'Skor TMDB '.number_format((float) $movie['rating'], 1).'/10';
            }

            return '• '.$movie['title'].($details === [] ? '' : ' ('.implode(' · ', $details).')')."\n  ".$movie['url'];
        }, array_slice($context['movies'], 0, 6));

        return "Pilihan dari dataset CINÉLOG:\n".implode("\n", $recommendations)
            ."\n\nSkor yang dicantumkan berasal dari TMDB.";
    }

    private function history(string $sessionId): string
    {
        if (! class_exists(Client::class) || ! config('services.mongodb.uri')) {
            return 'Tidak ada riwayat tersimpan.';
        }
        try {
            $client = new Client(config('services.mongodb.uri'));
            $rows = $client->selectDatabase(config('services.mongodb.database'))->selectCollection('chat_messages')
                ->find(['session_id' => $sessionId], ['sort' => ['created_at' => -1], 'limit' => 6])->toArray();

            return implode("\n", array_reverse(array_map(fn ($row) => ($row['user'] ?? '').' => '.($row['assistant'] ?? ''), $rows))) ?: 'Tidak ada riwayat tersimpan.';
        } catch (\Throwable $e) {
            Log::warning('MongoDB chat history read failed.', ['message' => $e->getMessage()]);

            return 'Tidak ada riwayat tersimpan.';
        }
    }

    private function saveHistory(string $sessionId, string $user, string $assistant): void
    {
        if (! class_exists(Client::class) || ! config('services.mongodb.uri')) {
            return;
        }
        try {
            $client = new Client(config('services.mongodb.uri'));
            $client->selectDatabase(config('services.mongodb.database'))->selectCollection('chat_messages')->insertOne([
                'session_id' => $sessionId,
                'user' => Str::limit($user, 1000, ''),
                'assistant' => Str::limit($assistant, 4000, ''),
                'created_at' => new UTCDateTime,
            ]);
        } catch (\Throwable $e) {
            Log::warning('MongoDB chat history write failed.', ['message' => $e->getMessage()]);
        }
    }
}
