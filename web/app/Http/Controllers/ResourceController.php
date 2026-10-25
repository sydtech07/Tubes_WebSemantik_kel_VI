<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Services\SparqlClient;
use Illuminate\Http\Request;

/**
 * Membuat IRI di RDF (http://localhost:8000/resource/...) benar-benar bisa dibuka.
 *  - Accept: text/turtle  -> 200 + data RDF resource itu (DESCRIBE dari Fuseki)
 *  - selain itu           -> 303 See Other ke halaman manusia
 */
class ResourceController extends Controller
{
    public function movie(Request $request, int $tmdbId, SparqlClient $sparql)
    {
        $movie = Movie::where('tmdb_id', $tmdbId)->firstOrFail();

        if ($request->prefers(['text/html', 'application/xhtml+xml', 'text/turtle']) === 'text/turtle') {
            return response($sparql->describe($sparql->resource('movie', $tmdbId)), 200, [
                'Content-Type' => 'text/turtle; charset=utf-8',
                'Vary'         => 'Accept',
            ]);
        }

        return redirect()->route('film.show', $movie, 303)->header('Vary', 'Accept');
    }

    public function genre(int $genreId)
    {
        return redirect()->route('home', ['genre' => $genreId], 303);
    }
}
