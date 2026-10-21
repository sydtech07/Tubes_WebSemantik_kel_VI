<?php

namespace App\Http\Controllers;

use App\Models\Genre;
use App\Models\Movie;
use App\Models\Person;
use App\Services\SparqlClient;
use Illuminate\Support\Facades\DB;
use Throwable;

class StatusController extends Controller
{
    /** Halaman diagnosa: membuktikan Laravel terhubung ke MariaDB dan Fuseki (bahan demo/presentasi). */
    public function __invoke(SparqlClient $sparql)
    {
        $checks = [];

        // 1) MariaDB lewat Eloquent
        try {
            $one = fn (string $sql) => (int) DB::selectOne($sql)->n;
            $checks[] = ['name' => 'MariaDB (Eloquent)', 'ok' => true, 'detail' => [
                'film'       => Movie::count(),
                'genre'      => Genre::count(),
                'orang'      => Person::count(),
                'trigger'    => $one('SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'),
                'routine (function+procedure)' => $one('SELECT COUNT(*) AS n FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()'),
                'view'       => $one('SELECT COUNT(*) AS n FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()'),
            ]];
        } catch (Throwable $e) {
            $checks[] = ['name' => 'MariaDB (Eloquent)', 'ok' => false, 'detail' => $e->getMessage()];
        }

        // 2) Fuseki lewat SPARQL
        try {
            $triples = $sparql->select('SELECT (COUNT(*) AS ?n) WHERE { ?s ?p ?o }')[0]['n'] ?? '?';
            $films   = $sparql->select($sparql->prefixes() . 'SELECT (COUNT(*) AS ?n) WHERE { ?f a cin:Movie }')[0]['n'] ?? '?';
            $checks[] = ['name' => 'Fuseki (SPARQL)', 'ok' => true, 'detail' => [
                'triple'            => $triples,
                'film di RDF'       => $films,
                'endpoint'          => config('cinelog.fuseki_url') . '/sparql',
            ]];
        } catch (Throwable $e) {
            $checks[] = ['name' => 'Fuseki (SPARQL)', 'ok' => false, 'detail' => $e->getMessage()];
        }

        return view('status', ['checks' => $checks]);
    }
}
