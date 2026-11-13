<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WatchlistController extends Controller
{
    public function store(Request $request, Movie $movie)
    {
        DB::table('watchlists')->insertOrIgnore([
            'user_id' => $request->user()->id,
            'movie_id' => $movie->id,
            'added_at' => now(),
        ]);

        return back()->with('status', 'Film ditambahkan ke watchlist.');
    }

    public function destroy(Request $request, Movie $movie)
    {
        $request->user()->watchlistMovies()->detach($movie->id);

        return back()->with('status', 'Film dihapus dari watchlist.');
    }
}
