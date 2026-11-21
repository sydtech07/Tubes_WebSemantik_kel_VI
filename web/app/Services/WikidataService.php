<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WikidataService
{
    public const ENDPOINT = 'https://query.wikidata.org/sparql';

    private const FIELDS = [
        'P495' => 'Negara asal',
        'P364' => 'Bahasa asli',
        'P272' => 'Rumah produksi',
        'P750' => 'Distributor',
        'P166' => 'Penghargaan',
    ];

    public function forMovie(?string $reference): array
    {
        if (! preg_match('~^(?:https?://www\.wikidata\.org/(?:entity|wiki)/)?(Q[1-9][0-9]*)$~D', $reference ?? '', $matches)) {
            return ['status' => 'unlinked', 'groups' => []];
        }

        $id = $matches[1];
        $key = 'cinelog.wikidata.v1.'.$id;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $query = $this->query($id);
        $result = [
            'id' => $id,
            'url' => 'https://www.wikidata.org/wiki/'.$id,
            'endpoint' => self::ENDPOINT,
            'query_url' => 'https://query.wikidata.org/#'.rawurlencode($query),
            'groups' => [],
        ];

        try {
            $response = Http::accept('application/sparql-results+json')
                ->withUserAgent('Cinelog/1.0 (academic film catalog; '.config('app.url').')')
                ->connectTimeout(4)
                ->timeout(15)
                ->get(self::ENDPOINT, ['query' => $query, 'format' => 'json'])
                ->throw();

            $rows = $response->json('results.bindings');
            if (! is_array($rows)) {
                throw new RuntimeException('Respons SPARQL Wikidata tidak valid.');
            }

            $values = [];
            foreach ($rows as $row) {
                $property = basename($row['property']['value'] ?? '');
                $uri = $row['value']['value'] ?? '';
                $label = $row['label']['value'] ?? '';
                if (! isset(self::FIELDS[$property]) || ! is_string($label) || $label === ''
                    || ! preg_match('~^https?://www\.wikidata\.org/entity/(Q[1-9][0-9]*)$~D', $uri, $entity)) {
                    continue;
                }
                $values[$property][$entity[1]] = [
                    'label' => $label,
                    'url' => 'https://www.wikidata.org/wiki/'.$entity[1],
                ];
            }

            foreach (self::FIELDS as $property => $label) {
                if (! empty($values[$property])) {
                    $result['groups'][] = ['label' => $label, 'items' => array_values($values[$property])];
                }
            }
            $result['status'] = $result['groups'] === [] ? 'empty' : 'ready';
            $result['fetched_at'] = now()->toIso8601String();
            Cache::put($key, $result, $result['status'] === 'ready' ? 86400 : 3600);
        } catch (Throwable $e) {
            Log::warning('Kueri Wikidata gagal.', ['wikidata_id' => $id, 'error' => $e->getMessage()]);
            $result['status'] = 'unavailable';
            Cache::put($key, $result, 60);
        }

        return $result;
    }

    private function query(string $id): string
    {
        return <<<SPARQL
        PREFIX wd: <http://www.wikidata.org/entity/>
        PREFIX wdt: <http://www.wikidata.org/prop/direct/>
        PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

        SELECT DISTINCT ?property ?value ?label WHERE {
          VALUES ?film { wd:{$id} }
          VALUES ?property { wdt:P495 wdt:P364 wdt:P272 wdt:P750 wdt:P166 }
          ?film ?property ?value .
          FILTER(ISIRI(?value))
          OPTIONAL { ?value rdfs:label ?labelId . FILTER(LANG(?labelId) = "id") }
          OPTIONAL { ?value rdfs:label ?labelEn . FILTER(LANG(?labelEn) = "en") }
          BIND(COALESCE(?labelId, ?labelEn, STRAFTER(STR(?value), "/entity/")) AS ?label)
        }
        ORDER BY ?property ?label
        LIMIT 100
        SPARQL;
    }
}
