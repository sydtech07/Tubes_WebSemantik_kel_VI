<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /** Katalog film + pencarian judul sederhana (versi awal; pencarian semantik menyusul). */
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $movies = Movie::query()
            ->with('genres')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $query->where(function ($w) use ($like) {
                    $w->where('title', 'like', $like)->orWhere('original_title', 'like', $like);
                });
            })
            ->orderByDesc('tmdb_popularity')
            ->paginate(24)
            ->withQueryString();

        return view('home', ['movies' => $movies, 'q' => $q]);
    }
}
