<?php

namespace Tests\Feature;

use App\Services\MovieRepository;
use App\Services\WikidataService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WikidataIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function binding(string $property, string $id, string $label): array
    {
        return [
            'property' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/prop/direct/'.$property],
            'value' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/'.$id],
            'label' => ['type' => 'literal', 'value' => $label],
        ];
    }

    private function film(?string $reference): void
    {
        $this->mock(MovieRepository::class)->shouldReceive('detail')->with('film-contoh')
            ->andReturn(['wikidata' => $reference]);
    }

    public function test_local_rdf_link_drives_external_sparql_and_visible_facts(): void
    {
        $this->film('http://www.wikidata.org/entity/Q123');
        Http::fake([WikidataService::ENDPOINT.'*' => Http::response(['results' => ['bindings' => [
            $this->binding('P495', 'Q252', 'Indonesia'),
            $this->binding('P272', 'Q456', 'Studio Contoh'),
        ]]])]);

        $this->get('/film/film-contoh/wikidata')->assertOk()
            ->assertSee('Negara asal')->assertSee('Indonesia')->assertSee('Studio Contoh')
            ->assertSee('Lihat kueri')->assertSee('https://www.wikidata.org/wiki/Q123');

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), WikidataService::ENDPOINT)
            && $request->method() === 'GET'
            && str_contains($request['query'], 'VALUES ?film { wd:Q123 }')
            && str_contains($request['query'], 'wdt:P495')
            && $request->hasHeader('Accept', 'application/sparql-results+json'));
    }

    public function test_successful_results_are_deduplicated_and_cached_per_entity(): void
    {
        $row = $this->binding('P495', 'Q252', 'Indonesia');
        Http::fake([WikidataService::ENDPOINT.'*' => Http::response(['results' => ['bindings' => [$row, $row]]])]);
        $service = app(WikidataService::class);
        $first = $service->forMovie('Q123');
        $this->assertSame($first, $service->forMovie('https://www.wikidata.org/entity/Q123'));
        $this->assertCount(1, $first['groups'][0]['items']);
        $service->forMovie('Q456');
        Http::assertSentCount(2);
    }

    public function test_missing_or_unsafe_identifier_never_reaches_external_endpoint(): void
    {
        Http::fake();
        foreach ([null, '', 'Q0', 'Q123 } UNION { ?s ?p ?o', 'https://evil.test/Q123'] as $value) {
            $this->assertSame('unlinked', app(WikidataService::class)->forMovie($value)['status']);
        }
        $this->film(null);
        $this->get('/film/film-contoh/wikidata')->assertOk()->assertSee('belum terhubung');
        Http::assertNothingSent();
    }

    public function test_empty_result_is_distinct_from_endpoint_failure(): void
    {
        $this->film('Q123');
        Http::fake([WikidataService::ENDPOINT.'*' => Http::response(['results' => ['bindings' => []]])]);
        $this->get('/film/film-contoh/wikidata')->assertOk()->assertSee('belum tersedia')->assertDontSee('Coba lagi');
    }

    public function test_rate_limit_does_not_break_film_and_failure_cache_expires(): void
    {
        $this->film('Q123');
        Http::fakeSequence()->push([], 429)->push(['results' => ['bindings' => [$this->binding('P495', 'Q252', 'Indonesia')]]]);
        $this->get('/film/film-contoh/wikidata')->assertOk()->assertSee('Coba lagi');
        $this->get('/film/film-contoh/wikidata')->assertOk()->assertSee('Informasi utama film tetap tersedia');
        Http::assertSentCount(1);
        $this->travel(61)->seconds();
        $this->get('/film/film-contoh/wikidata')->assertOk()->assertSee('Indonesia');
        Http::assertSentCount(2);
    }

    public function test_connection_failure_is_handled(): void
    {
        Http::fake([WikidataService::ENDPOINT.'*' => Http::failedConnection()]);
        $this->assertSame('unavailable', app(WikidataService::class)->forMovie('Q123')['status']);
    }

    public function test_malformed_response_is_not_claimed_as_empty_data(): void
    {
        Http::fake([WikidataService::ENDPOINT.'*' => Http::response('<html>Error</html>')]);
        $this->assertSame('unavailable', app(WikidataService::class)->forMovie('Q123')['status']);
    }

    public function test_external_labels_are_escaped_and_unsafe_links_are_discarded(): void
    {
        $this->film('Q123');
        $unsafe = $this->binding('P495', 'Q252', 'Unsafe');
        $unsafe['value']['value'] = 'javascript:alert(1)';
        Http::fake([WikidataService::ENDPOINT.'*' => Http::response(['results' => ['bindings' => [
            $unsafe, $this->binding('P272', 'Q456', '<script>alert(1)</script>'),
        ]]])]);
        $this->get('/film/film-contoh/wikidata')->assertOk()
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false)->assertDontSee('javascript:', false);
    }

    public function test_unknown_film_does_not_query_wikidata(): void
    {
        Http::fake();
        $this->mock(MovieRepository::class)->shouldReceive('detail')->with('tidak-ada')->andReturnNull();
        $this->get('/film/tidak-ada/wikidata')->assertNotFound();
        Http::assertNothingSent();
    }
}
