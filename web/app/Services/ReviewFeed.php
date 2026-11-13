<?php

namespace App\Services;

use App\Models\ExternalReview;
use App\Models\Movie;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

class ReviewFeed
{
    public function forMovie(Movie $movie, string $source, string $sort)
    {
        $local = DB::table('reviews')->where('movie_id', $movie->id)
            ->selectRaw("'cinelog' as source, id, rating, updated_at as review_date");
        $external = DB::table('external_reviews')->where('movie_id', $movie->id)->where('source', 'tmdb')
            ->selectRaw("'tmdb' as source, id, rating, posted_at as review_date");
        $query = DB::query()->fromSub($local->unionAll($external), 'review_feed')
            ->when($source !== 'all', fn ($query) => $query->where('source', $source));

        if (in_array($sort, ['high', 'low'], true)) {
            $query->orderByRaw('rating IS NULL')->orderBy('rating', $sort === 'high' ? 'desc' : 'asc');
        }

        $page = $query->orderByRaw('review_date IS NULL')->orderByDesc('review_date')
            ->orderBy('source')->orderByDesc('id')->paginate(10, ['*'], 'ulasan_page');
        $items = $page->getCollection();
        $localReviews = Review::with('user')->whereIn('id', $items->where('source', 'cinelog')->pluck('id'))->get()->keyBy('id');
        $externalReviews = ExternalReview::whereIn('id', $items->where('source', 'tmdb')->pluck('id'))->get()->keyBy('id');

        return $page->through(fn ($item) => [
            'source' => $item->source,
            'review' => ($item->source === 'cinelog' ? $localReviews : $externalReviews)->get($item->id),
        ]);
    }
}
