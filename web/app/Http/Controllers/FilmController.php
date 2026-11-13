<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Services\MovieRepository;
use App\Services\ReviewFeed;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class FilmController extends Controller
{
    /**
     * Halaman manusia untuk satu film. URL ini tercatat di RDF sebagai schema:url.
     * Data deskriptif (judul, sinopsis, genre, kru, pemeran) dari Fuseki lewat SPARQL;
     * rating dan jumlah ulasan dari MariaDB lewat Eloquent ($movie).
     */
    public function show(Request $request, Movie $movie, MovieRepository $repo, ReviewFeed $reviewFeed)
    {
        try {
            $film = $repo->detail($movie->slug);
        } catch (Throwable $e) {
            report($e);
            abort(503, 'Server data semantik (Fuseki) tidak dapat dihubungi.');
        }

        abort_if($film === null, 404, 'Film ini belum ada di dataset RDF.');

        $source = $request->string('sumber')->toString();
        $source = in_array($source, ['cinelog', 'tmdb'], true) ? $source : 'all';
        $sort = $request->string('urutan_ulasan')->toString();
        $sort = in_array($sort, ['high', 'low'], true) ? $sort : 'new';
        $communityCount = $movie->reviews()->count();
        $externalCount = $movie->externalReviews()->where('source', 'tmdb')->count();

        return view('film', [
            'movie' => $movie,
            'viewerReviews' => $reviewFeed->forMovie($movie, $source, $sort),
            'reviewSource' => $source,
            'reviewSort' => $sort,
            'communityReviewsCount' => $communityCount,
            'viewerReviewsCount' => $communityCount + $externalCount,
            'myReview' => auth()->check() ? $movie->reviews()->where('user_id', auth()->id())->first() : null,
            'inWatchlist' => auth()->check() && auth()->user()->watchlistMovies()->where('movies.id', $movie->id)->exists(),
            'film' => $film,
            'externalReviewsCount' => $externalCount,
            'ogDescription' => Str::limit(trim((string) $film['description']), 200),
        ]);
    }
}
