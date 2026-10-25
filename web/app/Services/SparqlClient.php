<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Klien SPARQL minimal untuk Apache Jena Fuseki.
 * Semua query aplikasi ke data semantik lewat kelas ini (satu pintu, mudah diuji dan ditunjukkan saat demo).
 *
 * 2B-2: ditambah lit()/iri() agar input pengguna aman dimasukkan ke query, resource()/filmUrl()
 *       untuk membentuk IRI, dan describe() untuk jawaban text/turtle.
 */
class SparqlClient
{
    private string $dataset;

    public function __construct(?string $dataset = null)
    {
        $this->dataset = rtrim($dataset ?? config('cinelog.fuseki_url'), '/');
    }

    /** Awalan PREFIX standar yang dipakai query aplikasi. */
    public function prefixes(): string
    {
        $cin = rtrim(config('cinelog.base_uri'), '/') . '/ontology#';

        return "PREFIX schema: <https://schema.org/>\n"
             . "PREFIX cin: <{$cin}>\n"
             . "PREFIX owl: <http://www.w3.org/2002/07/owl#>\n"
             . "PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>\n"
             . "PREFIX skos: <http://www.w3.org/2004/02/skos/core#>\n";
    }

    /** Literal string SPARQL yang aman: tanda kutip, backslash, dan baris baru di-escape. */
    public function lit(string $value): string
    {
        $escaped = str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $value
        );

        return '"' . $escaped . '"';
    }

    /** IRI SPARQL yang aman. Menolak karakter yang bisa menutup <...> atau menyisipkan query. */
    public function iri(string $iri): string
    {
        if (! preg_match('/^[^\s<>"{}|\\\\^`]+$/', $iri)) {
            throw new InvalidArgumentException('IRI tidak valid: ' . mb_substr($iri, 0, 80));
        }

        return '<' . $iri . '>';
    }

    /** IRI resource RDF, mis. resource('movie', 969681) => http://localhost:8000/resource/movie/969681 */
    public function resource(string $type, int|string $id): string
    {
        return rtrim(config('cinelog.base_uri'), '/') . '/resource/' . $type . '/' . $id;
    }

    /** URL halaman manusia sebuah film (sama dengan nilai schema:url di RDF). */
    public function filmUrl(string $slug): string
    {
        return rtrim(config('cinelog.base_uri'), '/') . '/film/' . $slug;
    }

    /**
     * Jalankan SELECT. Mengembalikan array baris; tiap baris array [nama_variabel => nilai string].
     * Variabel yang tidak terikat (OPTIONAL kosong) tidak muncul di baris itu.
     */
    public function select(string $query): array
    {
        $json = $this->post($query, 'application/sparql-results+json')->json() ?? [];
        $rows = [];
        foreach ($json['results']['bindings'] ?? [] as $binding) {
            $row = [];
            foreach ($binding as $var => $cell) {
                $row[$var] = $cell['value'];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** Jalankan ASK. */
    public function ask(string $query): bool
    {
        $json = $this->post($query, 'application/sparql-results+json')->json() ?? [];

        return (bool) ($json['boolean'] ?? false);
    }

    /** DESCRIBE sebuah resource; hasilnya teks Turtle. */
    public function describe(string $iri): string
    {
        return $this->post('DESCRIBE ' . $this->iri($iri), 'text/turtle')->body();
    }

    private function post(string $query, string $accept): Response
    {
        $response = Http::asForm()
            ->withHeaders([
                'Accept'     => $accept,
                'User-Agent' => 'CinelogStudentProject/0.1 (tugas besar Web Semantik & MSBD)',
            ])
            ->timeout(30)
            ->post($this->dataset . '/sparql', ['query' => $query]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Fuseki menjawab HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300)
            );
        }

        return $response;
    }
}
