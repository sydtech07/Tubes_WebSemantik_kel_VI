<?php

namespace App\Http\Controllers;

use App\Services\ScreeningRepository;
use Illuminate\Http\Request;
use Throwable;

class ScreeningController extends Controller
{
    /** Halaman film dengan jadwal tayang aktif/akan datang dari Fuseki. */
    public function index(
    Request $request,
    ScreeningRepository $repository
) 
    {
        $city = trim($request->string('kota')->toString());
        $genreId = filter_var($request->input('genre'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

        try {
            $cities = $repository->cities();
            $city = in_array($city, $cities, true) ? $city : null;
            $genres = $repository->genres();
            $genreIds = array_column($genres, 'id');
            $genreId = in_array($genreId, $genreIds, true) ? $genreId : null;

            $movies = $repository->nowShowing(7, $city, $genreId);

            $slugs = array_column($movies, 'slug');

            $ratings = empty($slugs)
                ? collect()
                : \App\Models\Movie::whereIn('slug', $slugs)
                    ->withCount('externalReviews')
                    ->get([
                        'slug',
                        'avg_rating',
                        'reviews_count'
                    ])
                    ->keyBy('slug');

            $error = null;
        } catch (Throwable $e) {
            report($e);

            $cities = [];
            $genres = [];
            $movies = [];
            $ratings = collect();
            $city = null;
            $genreId = null;

            $error = 'Data film sedang tayang tidak dapat diambil dari Fuseki.';
        }

        return response()->view('sedang-tayang', [
            'movies' => $movies,
            'cities' => $cities,
            'selectedCity' => $city,
            'genres' => $genres,
            'selectedGenre' => $genreId,
            'ratings' => $ratings,
            'error' => $error,
            'windowDays' => 7,
        ])->header('Cache-Control', 'no-store, private');
    }
}
