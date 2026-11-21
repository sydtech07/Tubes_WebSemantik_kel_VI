<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Services\MovieRepository;
use App\Services\ReviewFeed;
use App\Services\ScreeningRepository;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class FilmController extends Controller
{
    /**
     * Halaman manusia untuk satu film. URL ini tercatat di RDF sebagai schema:url.
     * Data deskriptif dan skor TMDB dari Fuseki lewat SPARQL; ulasan eksternal TMDB
     * dan watchlist dibaca dari MariaDB.
     */
    public function show(Request $request, string $movie, MovieRepository $repo, ReviewFeed $reviewFeed, ScreeningRepository $screenings)
    {
        $slug = trim($movie);
        $movieModel = Movie::firstOrNew(['slug' => $slug]);
        $recommendations = [];
        $screeningCinemas = [];
        try {
            $film = $repo->detail($slug);
            $recommendations = $repo->recommendations($slug);
            $screeningCinemas = $screenings->cinemasForMovie($slug);
        } catch (Throwable $e) {
            report($e);
            abort(503, 'Server data semantik (Fuseki) tidak dapat dihubungi.');
        }

        abort_if($film === null, 404, 'Film ini belum ada di dataset RDF.');
        if (! $movieModel->exists) {
            $tmdbId = (int) Str::match('/(\d+)$/', $slug);

            if ($tmdbId > 0 && ! empty($film['date'])) {
                $movieModel = Movie::create([
                    'tmdb_id' => $tmdbId,
                    'title' => $film['title'],
                    'original_title' => $film['altTitle'] ?? $film['title'],
                    'slug' => $slug,
                    'tagline' => $film['tagline'] ?? null,
                    'release_date' => Carbon::parse($film['date'])->toDateString(),
                    'runtime' => $film['runtime'] ?? null,
                    'original_language' => $film['lang'] ?? null,
                    'poster_path' => $film['poster'] ?? null,
                    'backdrop_path' => $film['backdrop'] ?? null,
                    'imdb_id' => $film['imdb'] ?? null,
                    'wikidata_id' => ! empty($film['wikidata'])
                        ? Str::afterLast(rtrim($film['wikidata'], '/'), '/')
                        : null,
                    'tmdb_vote_average' => $film['vote'] ?? null,
                    'tmdb_vote_count' => $film['votes'] ?? null,
                    'budget' => $film['budget'] ?? null,
                    'revenue' => $film['revenue'] ?? null,
                ]);
            }
        }

        $sort = $request->string('urutan_ulasan')->toString();
        $sort = in_array($sort, ['high', 'low'], true) ? $sort : 'new';
        $externalCount = $movieModel->exists ? $movieModel->externalReviews()->where('source', 'tmdb')->count() : 0;

        return view('film', [
            'movie' => $movieModel,
            'viewerReviews' => $movieModel->exists
                ? $reviewFeed->forMovie($movieModel, $sort)
                : new LengthAwarePaginator([], 0, 10),
            'reviewSort' => $sort,
            'viewerReviewsCount' => $externalCount,
            'inWatchlist' => auth()->check() && $movieModel->exists
                && auth()->user()->watchlistMovies()->where('movies.id', $movieModel->id)->exists(),
            'film' => $film,
            'recommendations' => $recommendations,
            'screeningCinemas' => $screeningCinemas,
            'externalReviewsCount' => $externalCount,
            'ogDescription' => Str::limit(trim((string) $film['description']), 200),
        ]);
    }
}
