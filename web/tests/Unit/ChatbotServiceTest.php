<?php

namespace Tests\Unit;

use App\Services\ChatbotService;
use App\Services\SparqlClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotServiceTest extends TestCase
{
    private function mockMovies(array $movies, ?callable $assertQuery = null): void
    {
        $this->mock(SparqlClient::class, function ($mock) use ($movies, $assertQuery) {
            $mock->shouldReceive('prefixes')->once()->andReturn('');
            $mock->shouldReceive('lit')->zeroOrMoreTimes()->andReturnUsing(
                fn (string $value) => '"'.addcslashes($value, '\\"').'"'
            );
            $select = $mock->shouldReceive('select')->once();
            if ($assertQuery !== null) {
                $select->withArgs(fn (string $query) => $assertQuery($query));
            }
            $select->andReturn($movies);
        });
    }

    private function datasetMovie(): array
    {
        return [
            'title' => 'Film Action dari Dataset',
            'url' => 'http://localhost:8000/film/film-action-2026-123',
            'genres' => 'Action, Adventure',
            'rating' => '8.4',
            'votes' => '1200',
            'overview' => 'Sinopsis yang dibaca dari Fuseki.',
        ];
    }

    public function test_it_uses_genre_recommendations_from_fuseki_as_gemini_context(): void
    {
        Config::set('services.gemini.api_key', 'test-api-key');
        Config::set('services.gemini.model', 'gemini-3.1-flash-lite');
        $this->mockMovies([$this->datasetMovie()], function (string $query) {
            return str_contains($query, 'LCASE(STR(?genreLabel)) IN ("action")')
                && str_contains($query, 'schema:genre')
                && str_contains($query, 'Sinopsis') === false;
        });
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Coba tonton film ini.']]]],
                ],
            ]),
        ]);

        $reply = app(ChatbotService::class)->askMovieBot('Rekomendasikan film action terbaik tahun 2026');

        $this->assertSame('Coba tonton film ini.', $reply);
        Http::assertSent(fn (Request $request) => $request->url() ===
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent'
            && $request->hasHeader('x-goog-api-key', 'test-api-key')
            && str_contains($request['contents'][0]['parts'][0]['text'], 'Rekomendasikan film action terbaik')
            && str_contains($request['contents'][0]['parts'][0]['text'], 'Film Action dari Dataset')
            && str_contains($request['contents'][0]['parts'][0]['text'], 'Sinopsis yang dibaca dari Fuseki.')
            && str_contains($request['contents'][0]['parts'][0]['text'], 'Skor TMDB: 8.4/10'));
    }

    public function test_it_returns_dataset_recommendations_when_gemini_is_unavailable(): void
    {
        Config::set('services.gemini.api_key', 'test-api-key');
        Config::set('services.gemini.model', 'gemini-3.1-flash-lite');
        $this->mockMovies([$this->datasetMovie()]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 503),
        ]);

        $reply = app(ChatbotService::class)->askMovieBot('Rekomendasi film?');

        $this->assertStringContainsString('Film Action dari Dataset', $reply);
        $this->assertStringContainsString('http://localhost:8000/film/film-action-2026-123', $reply);
        $this->assertStringContainsString('Skor TMDB 8.4/10', $reply);
    }

    public function test_it_searches_movie_summaries_and_keywords_for_context(): void
    {
        Config::set('services.gemini.api_key', 'test-api-key');
        Config::set('services.gemini.model', 'gemini-3.1-flash-lite');
        $this->mockMovies([$this->datasetMovie()], function (string $query) {
            return str_contains($query, 'schema:description')
                && str_contains($query, 'cin:hasKeyword')
                && str_contains($query, 'CONTAINS(LCASE(STR(COALESCE(?description, ""))), "angkasa")');
        });
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Film ini ada di dataset.']]]],
                ],
            ]),
        ]);

        $reply = app(ChatbotService::class)->askMovieBot('Film dengan cerita luar angkasa');

        $this->assertSame('Film ini ada di dataset.', $reply);
        Http::assertSent(fn (Request $request) => $request->url() ===
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent'
            && str_contains($request['contents'][0]['parts'][0]['text'], 'Sinopsis yang dibaca dari Fuseki.'));
    }

    public function test_it_reports_dataset_unavailability_instead_of_claiming_no_matches(): void
    {
        Config::set('services.gemini.api_key', 'test-api-key');
        $this->mock(SparqlClient::class, function ($mock) {
            $mock->shouldReceive('prefixes')->once()->andReturn('');
            $mock->shouldReceive('lit')->zeroOrMoreTimes()->andReturn('"action"');
            $mock->shouldReceive('select')->once()->andThrow(new \RuntimeException('Fuseki unavailable'));
        });
        Http::preventStrayRequests();

        $reply = app(ChatbotService::class)->askMovieBot('Rekomendasi film action');

        $this->assertStringContainsString('tidak dapat membaca knowledge graph', $reply);
        Http::assertNothingSent();
    }
}
