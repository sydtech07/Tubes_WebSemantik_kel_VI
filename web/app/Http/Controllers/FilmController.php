<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\Booking;
use App\Services\MovieRepository;
use App\Services\ReviewFeed;
use App\Services\ScreeningRepository;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Throwable;

class FilmController extends Controller
{
    /**
     * Halaman manusia untuk satu film. URL ini tercatat di RDF sebagai schema:url.
     * Data deskriptif (judul, sinopsis, genre, kru, pemeran) dari Fuseki lewat SPARQL;
     * rating dan jumlah ulasan dari MariaDB lewat Eloquent ($movie).
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
                    'wikidata_id' => !empty($film['wikidata'])
                        ? Str::afterLast(rtrim($film['wikidata'], '/'), '/')
                        : null,
                    'tmdb_vote_average' => $film['vote'] ?? null,
                    'tmdb_vote_count' => $film['votes'] ?? null,
                    'budget' => $film['budget'] ?? null,
                    'revenue' => $film['revenue'] ?? null,
                ]);
            }
        }

        $source = $request->string('sumber')->toString();
        $source = in_array($source, ['cinelog', 'tmdb'], true) ? $source : 'all';
        $sort = $request->string('urutan_ulasan')->toString();
        $sort = in_array($sort, ['high', 'low'], true) ? $sort : 'new';
        $communityCount = $movieModel->exists ? $movieModel->reviews()->count() : 0;
        $externalCount = $movieModel->exists ? $movieModel->externalReviews()->where('source', 'tmdb')->count() : 0;
        $canReview = auth()->check() && Booking::where('user_id', auth()->id())
            ->whereRaw('LOWER(TRIM(movie_slug)) = ?', [mb_strtolower($slug)])
            ->whereRaw("LOWER(status) IN ('paid', 'completed')")
            ->where('show_start', '<=', Carbon::now())
            ->exists();

        $canReview = $canReview && $movieModel->exists;
        // Review membutuhkan movie_id MariaDB karena tabel reviews memiliki FK.
        // Film RDF-only tetap dapat dilihat, tetapi tidak menampilkan tombol submit.

        return view('film', [
            'movie' => $movieModel,
            'viewerReviews' => $movieModel->exists
                ? $reviewFeed->forMovie($movieModel, $source, $sort)
                : new LengthAwarePaginator([], 0, 10),
            'reviewSource' => $source,
            'reviewSort' => $sort,
            'communityReviewsCount' => $communityCount,
            'viewerReviewsCount' => $communityCount + $externalCount,
            'myReview' => auth()->check() && $movieModel->exists
                ? $movieModel->reviews()->where('user_id', auth()->id())->first()
                : null,
            'inWatchlist' => auth()->check() && $movieModel->exists
                && auth()->user()->watchlistMovies()->where('movies.id', $movieModel->id)->exists(),
            'film' => $film,
            'recommendations' => $recommendations,
            'screeningCinemas' => $screeningCinemas,
            'externalReviewsCount' => $externalCount,
            'canReview' => $canReview,
            'ogDescription' => Str::limit(trim((string) $film['description']), 200),
        ]);
    }
}
