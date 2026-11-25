<?php

namespace App\Http\Controllers;

use App\Models\CinemaReview;
use App\Services\CinemaRepository;
use App\Services\CinemaRouting;
use App\Services\ScreeningRepository;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CinemaController extends Controller
{
    public function route(Request $request, CinemaRepository $repo, CinemaRouting $routing)
    {
        $data = $request->validate([
            'cinema_id' => ['required', 'string', 'max:100'],
            'origin.lat' => ['required', 'numeric', 'between:-90,90'],
            'origin.lng' => ['required', 'numeric', 'between:-180,180'],
            'profile' => ['required', Rule::in(['driving-car', 'motorcycle', 'foot-walking', 'cycling-regular'])],
        ]);
        $cinema = collect($repo->all())->firstWhere('id', $data['cinema_id']);
        abort_unless($cinema, 404, 'Bioskop tidak ditemukan.');

        return response()->json($routing->directions($data['origin'], $cinema, $data['profile']));
    }

    /** Peta + daftar bioskop Medan dan Sumatera Utara. */
    public function index(CinemaRepository $repo)
    {
        $cinemas = $repo->all();
        $ratings = CinemaReview::query()
            ->selectRaw('cinema_id, COUNT(*) as ratings_count, AVG(rating) as average_rating')
            ->groupBy('cinema_id')
            ->get()
            ->keyBy('cinema_id');
        $myRatings = auth()->check()
            ? auth()->user()->cinemaReviews()->get()->keyBy('cinema_id')
            : collect();

        foreach ($cinemas as &$cinema) {
            $aggregate = $ratings->get($cinema['id']);
            $cinema['ratings_count'] = (int) ($aggregate?->ratings_count ?? 0);
            $cinema['average_rating'] = $aggregate ? round((float) $aggregate->average_rating, 1) : null;
            $cinema['user_rating'] = $myRatings->get($cinema['id'])?->rating;
        }
        unset($cinema);

        // Penanda terstruktur (schema.org) agar daftar ini juga terbaca mesin, sejalan dengan tema web semantik proyek.
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'Direktori bioskop CINÉLOG',
            'itemListElement' => array_map(fn (array $c, int $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'item' => [
                    '@type' => 'MovieTheater',
                    'name' => $c['name'],
                    'address' => [
                        '@type' => 'PostalAddress',
                        'streetAddress' => $c['address'],
                        'addressLocality' => $c['city'],
                        'addressRegion' => $c['region'],
                        'addressCountry' => 'ID',
                    ],
                    'geo' => ['@type' => 'GeoCoordinates', 'latitude' => $c['lat'], 'longitude' => $c['lng']],
                    'url' => $c['site'],
                    ...($c['average_rating'] !== null ? [
                        'aggregateRating' => [
                            '@type' => 'AggregateRating',
                            'ratingValue' => $c['average_rating'],
                            'bestRating' => 5,
                            'reviewCount' => $c['ratings_count'],
                        ],
                    ] : []),
                ],
            ], $cinemas, array_keys($cinemas)),
        ];

        $cities = collect($cinemas)->countBy('city')->sortDesc();

        return response()->view('bioskop', [
            'cinemas' => $cinemas,
            'cities' => $cities,
            'jsonLd' => $jsonLd,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function schedule(Request $request, string $cinema, CinemaRepository $cinemas, ScreeningRepository $screenings)
    {
        $selected = collect($cinemas->all())->firstWhere('id', $cinema);
        abort_unless($selected, 404, 'Bioskop tidak ditemukan.');

        return response()->view('bioskop-jadwal', [
            'cinema' => $selected,
            'schedules' => $screenings->scheduleForCinema($cinema, 7, $request->string('film')->toString() ?: null),
            'selectedFilm' => $request->string('film')->toString() ?: null,
            'windowDays' => 7,
        ])->header('Cache-Control', 'no-store, private');
    }
}
