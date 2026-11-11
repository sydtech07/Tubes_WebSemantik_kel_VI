<?php

namespace App\Http\Controllers;

use App\Services\CinemaRepository;

class CinemaController extends Controller
{
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

        return view('bioskop', [
            'cinemas' => $cinemas,
            'cities'  => $cities,
            'jsonLd'  => $jsonLd,
        ]);
    }
}
