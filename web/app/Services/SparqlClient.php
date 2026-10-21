<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien SPARQL minimal untuk Apache Jena Fuseki.
 * Semua query aplikasi ke data semantik lewat kelas ini (satu pintu, mudah diuji dan ditunjukkan saat demo).
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

    /**
     * Jalankan SELECT. Mengembalikan array baris; tiap baris array [nama_variabel => nilai string].
     * Variabel yang tidak terikat (OPTIONAL kosong) tidak muncul di baris itu.
     */
    public function select(string $query): array
    {
        $json = $this->post($query);
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
        return (bool) ($this->post($query)['boolean'] ?? false);
    }

    private function post(string $query): array
    {
        $response = Http::asForm()
            ->withHeaders([
                'Accept'     => 'application/sparql-results+json',
                'User-Agent' => 'CinelogStudentProject/0.1 (tugas besar Web Semantik & MSBD)',
            ])
            ->timeout(30)
            ->post($this->dataset . '/sparql', ['query' => $query]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Fuseki menjawab HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300)
            );
        }

        return $response->json() ?? [];
    }
}
