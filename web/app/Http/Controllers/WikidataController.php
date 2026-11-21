<?php

namespace App\Http\Controllers;

use App\Services\MovieRepository;
use App\Services\WikidataService;
use Throwable;

class WikidataController extends Controller
{
    public function __invoke(string $movie, MovieRepository $movies, WikidataService $wikidata)
    {
        try {
            $film = $movies->detail(trim($movie));
        } catch (Throwable $e) {
            report($e);

            return response()->view('partials.wikidata-facts', [
                'data' => ['status' => 'unavailable', 'groups' => []],
            ], 503);
        }

        abort_if($film === null, 404);

        return response()->view('partials.wikidata-facts', [
            'data' => $wikidata->forMovie($film['wikidata'] ?? null),
        ]);
    }
}
