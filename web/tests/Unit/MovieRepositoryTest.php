<?php

namespace Tests\Unit;

use App\Services\MovieRepository;
use App\Services\SparqlClient;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

/** Menguji pembentukan query SPARQL tanpa menghubungi Fuseki (select() ditiru). */
class MovieRepositoryTest extends TestCase
{
    /** @param array<int, string> $captured diisi semua query yang dikirim repository */
    private function repo(array &$captured, int $total = 0): MovieRepository
    {
        $sparql = Mockery::mock(SparqlClient::class, ['http://fuseki.test/cinelog'])->makePartial();
        $sparql->shouldReceive('select')->andReturnUsing(function (string $q) use (&$captured, $total) {
            $captured[] = $q;

            // query COUNT dijawab $total; query halaman dijawab kosong
            return str_contains($q, 'COUNT(') ? [['n' => (string) $total]] : [];
        });

        return new MovieRepository($sparql);
    }

    public function test_kata_kunci_pencarian_di_escape_sehingga_tidak_bisa_menyisipkan_query(): void
    {
        $captured = [];
        $this->repo($captured)->catalog('A" } UNION { ?s ?p ?o', null, 'populer', 1, 24);

        $this->assertCount(1, $captured, 'total 0 harus menghentikan query halaman');
        $this->assertStringContainsString('a\\" } union { ?s ?p ?o', $captured[0]);
        $this->assertStringNotContainsString('CONTAINS(LCASE(STR(?title)), "a" }', $captured[0]);
    }

    public function test_urutan_tak_dikenal_jatuh_ke_populer(): void
    {
        $captured = [];
        $this->repo($captured, 5)->catalog('', null, 'DROP ALL', 2, 24);

        $page = end($captured);
        $this->assertStringContainsString('ORDER BY DESC(?pop) ?m', $page);
        $this->assertStringContainsString('LIMIT 24 OFFSET 24', $page);
        $this->assertStringNotContainsString('DROP', $page);
    }

    public function test_filter_genre_memakai_iri_resource_genre(): void
    {
        $captured = [];
        $this->repo($captured)->catalog('', 18, 'populer', 1, 24);

        $expected = 'schema:genre <' . rtrim(config('cinelog.base_uri'), '/') . '/resource/genre/18>';
        $this->assertStringContainsString($expected, $captured[0]);
    }

    public function test_iri_berbahaya_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SparqlClient('http://fuseki.test/cinelog'))->iri('http://x/> } DELETE { ?s ?p ?o');
    }
}
