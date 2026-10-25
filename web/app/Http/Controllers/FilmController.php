<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Services\MovieRepository;
use Illuminate\Support\Str;
use Throwable;

class FilmController extends Controller
{
    /**
     * Halaman manusia untuk satu film. URL ini tercatat di RDF sebagai schema:url.
     * Data deskriptif (judul, sinopsis, genre, kru, pemeran) dari Fuseki lewat SPARQL;
     * rating dan jumlah ulasan dari MariaDB lewat Eloquent ($movie).
     */
    public function show(Movie $movie, MovieRepository $repo)
    {
        try {
            $film = $repo->detail($movie->slug);
        } catch (Throwable $e) {
            report($e);
            abort(503, 'Server data semantik (Fuseki) tidak dapat dihubungi.');
        }

        abort_if($film === null, 404, 'Film ini belum ada di dataset RDF.');

        return view('film', [
            'movie'         => $movie,
            'film'          => $film,
            'ogDescription' => Str::limit(trim((string) $film['description']), 200),
        ]);
    }
}
