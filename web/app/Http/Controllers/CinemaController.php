<?php

namespace App\Http\Controllers;

use App\Services\CinemaRepository;
use App\Services\CinemaRouting;
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

        // Penanda terstruktur (schema.org) agar daftar ini juga terbaca mesin, sejalan dengan tema web semantik proyek.
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'Bioskop di Medan dan Sumatera Utara',
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
                        'addressRegion' => 'Sumatera Utara',
                        'addressCountry' => 'ID',
                    ],
                    'geo' => ['@type' => 'GeoCoordinates', 'latitude' => $c['lat'], 'longitude' => $c['lng']],
                    'url' => $c['site'],
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
}
