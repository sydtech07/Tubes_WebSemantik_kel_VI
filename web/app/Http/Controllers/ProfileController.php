<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        return view('profile', [
            'user' => $user,
            'watchlist' => $user->watchlistMovies()->orderByPivot('added_at', 'desc')->paginate(12, ['movies.*'], 'watchlist_page'),
            'cinemaReviewCount' => $user->cinemaReviews()->count(),
            'averageCinemaRating' => $user->cinemaReviews()->avg('rating'),
            'watchlistCount' => $user->watchlistMovies()->count(),
            'bookings' => $user->bookings()
                ->with('seats')
                ->latest()
                ->paginate(10, ['*'], 'bookings_page'),

            'bookingCount' => $user->bookings()->count(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($request->user()->id)],
        ]);
        $request->user()->update($data);

        return redirect()->route('profile')->with('status', 'Profil berhasil diperbarui.');
    }
}
