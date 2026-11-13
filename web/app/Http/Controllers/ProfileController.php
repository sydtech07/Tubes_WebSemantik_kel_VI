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
            'reviews' => $user->reviews()->with('movie')->latest('updated_at')->paginate(8, ['*'], 'reviews_page'),
            'watchlist' => $user->watchlistMovies()->orderByPivot('added_at', 'desc')->paginate(12, ['movies.*'], 'watchlist_page'),
            'reviewCount' => $user->reviews()->count(),
            'watchlistCount' => $user->watchlistMovies()->count(),
            'averageRating' => $user->reviews()->avg('rating'),
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
