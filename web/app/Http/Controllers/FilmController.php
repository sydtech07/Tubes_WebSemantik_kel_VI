<?php

namespace App\Http\Controllers;

use App\Models\Movie;

class FilmController extends Controller
{
    /**
     * Halaman manusia untuk satu film. URL ini tercatat di RDF sebagai schema:url,
     * jadi rute /film/{slug} wajib ada. (RDFa + Open Graph ditambahkan di tahap berikutnya.)
     */
    public function show(Movie $movie)
    {
        $movie->load(['genres', 'cast.person', 'crew.person']);

        $directors = $movie->crew->where('role', 'Director');

        return view('film', ['movie' => $movie, 'directors' => $directors]);
    }
}
