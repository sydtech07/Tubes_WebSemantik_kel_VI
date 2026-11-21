<?php

namespace App\Services;

use App\Models\ExternalReview;
use App\Models\Movie;

class ReviewFeed
{
    public function forMovie(Movie $movie, string $sort)
    {
        $query = ExternalReview::where('movie_id', $movie->id)->where('source', 'tmdb');

        if (in_array($sort, ['high', 'low'], true)) {
            $query->orderByRaw('rating IS NULL')->orderBy('rating', $sort === 'high' ? 'desc' : 'asc');
        }

        $page = $query->orderByRaw('posted_at IS NULL')->orderByDesc('posted_at')
            ->orderByDesc('id')->paginate(10, ['*'], 'ulasan_page');

        return $page->through(fn (ExternalReview $review) => ['review' => $review]);
    }
}
