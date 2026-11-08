<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Services\MovieRepository;
use Illuminate\Support\Facades\Cache;
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

        // Ulasan TMDB: 2 query + render markdown puluhan ulasan itu mahal, padahal jarang berubah -> simpan HTML jadinya 10 menit.
        // Setelah mengimpor ulasan baru: php artisan cache:clear
        $reviews = Cache::remember("cinelog.reviews.v1.{$movie->id}", 600, function () use ($movie) {
            $items = $movie->externalReviews()->orderByDesc('posted_at')->limit(60)->get();
            $count = $movie->externalReviews()->count();

            return [
                'count' => $count,
                'html'  => view('partials.external-reviews', ['externalReviews' => $items, 'externalReviewsCount' => $count])->render(),
            ];
        });

        return view('film', [
            'movie'         => $movie,
            'film'          => $film,
            'externalReviewsCount' => $reviews['count'],
            'reviewsHtml'     => $reviews['html'],
            'ogDescription' => Str::limit(trim((string) $film['description']), 200),
        ]);
    }
}
