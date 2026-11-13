<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public static function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,10'],
            'title' => ['nullable', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:10000'],
            'contains_spoiler' => ['sometimes', 'boolean'],
        ];
    }

    public function store(Request $request, Movie $movie)
    {
        $data = $request->validate(self::rules(), ['rating.between' => 'Pilih rating antara 1 sampai 10.']);
        $data['contains_spoiler'] = $request->boolean('contains_spoiler');
        Review::upsert([
            array_merge($data, [
                'user_id' => $request->user()->id,
                'movie_id' => $movie->id,
                'title' => $data['title'] ?? null,
                'body' => $data['body'] ?? null,
            ]),
        ], ['user_id', 'movie_id'], ['rating', 'title', 'body', 'contains_spoiler', 'updated_at']);

        return redirect()->to(route('film.show', $movie).'#ulasan-pengguna')->with('status', 'Rating dan ulasanmu berhasil disimpan.');
    }

    public function destroy(Request $request, Review $review)
    {
        abort_unless($review->user_id === $request->user()->id, 403);
        $movie = $review->movie;
        $review->delete();

        if ($request->input('return_to') === 'profile') {
            return redirect()->to(route('profile').'#rating-saya')->with('status', 'Rating dan ulasanmu berhasil dihapus.');
        }

        return redirect()->to(route('film.show', $movie).'#ulasan-pengguna')->with('status', 'Rating dan ulasanmu berhasil dihapus.');
    }
}
