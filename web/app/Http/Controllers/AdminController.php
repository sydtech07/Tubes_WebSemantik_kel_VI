<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function index(Request $request)
    {
        $start = today()->subDays(13);
        $signups = User::where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')->groupByRaw('DATE(created_at)')->pluck('total', 'day');
        $reviews = Review::where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')->groupByRaw('DATE(created_at)')->pluck('total', 'day');
        $activity = collect(range(0, 13))->map(function ($offset) use ($start, $signups, $reviews) {
            $date = $start->copy()->addDays($offset);

            return ['label' => $date->format('d/m'), 'users' => (int) ($signups[$date->toDateString()] ?? 0), 'reviews' => (int) ($reviews[$date->toDateString()] ?? 0)];
        });
        $distribution = Review::selectRaw('rating, COUNT(*) as total')->groupBy('rating')->pluck('total', 'rating');
        $q = trim((string) $request->query('q', ''));

        return view('admin.dashboard', [
            'userCount' => User::count(),
            'activeCount' => User::where('last_seen_at', '>=', now()->subMinutes(15))->count(),
            'reviewCount' => Review::count(),
            'watchlistCount' => DB::table('watchlists')->count(),
            'users' => User::withCount('reviews')->when($q !== '', fn ($query) => $query->where(fn ($search) => $search->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
                ->orderByDesc('last_seen_at')->orderByDesc('id')->paginate(10, ['*'], 'users_page')->withQueryString(),
            'reviews' => Review::with(['user', 'movie'])->latest('updated_at')->paginate(10, ['*'], 'reviews_page')->withQueryString(),
            'activity' => $activity,
            'chartMax' => max(1, $activity->max('users'), $activity->max('reviews')),
            'distribution' => $distribution,
            'ratingMax' => max(1, $distribution->max() ?? 0),
            'q' => $q,
        ]);
    }

    public function create()
    {
        return view('admin.review-form', ['review' => new Review, 'movies' => Movie::orderBy('title')->get(['id', 'title'])]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(array_merge(ReviewController::rules(), [
            'movie_id' => ['required', 'integer', 'exists:movies,id', Rule::unique('reviews')->where('user_id', $request->user()->id)],
        ]), ['movie_id.unique' => 'Kamu sudah memberi rating untuk film ini. Edit ulasan yang sudah ada.']);
        $data['contains_spoiler'] = $request->boolean('contains_spoiler');
        $request->user()->reviews()->create($data);

        return redirect()->to(route('admin.dashboard').'#moderasi')->with('status', 'Ulasan admin berhasil ditambahkan.');
    }

    public function edit(Review $review)
    {
        $review->load(['movie', 'user']);

        return view('admin.review-form', ['review' => $review, 'movies' => collect()]);
    }

    public function update(Request $request, Review $review)
    {
        $data = $request->validate(ReviewController::rules());
        $data['contains_spoiler'] = $request->boolean('contains_spoiler');
        $review->update($data);

        return redirect()->to(route('admin.dashboard').'#moderasi')->with('status', 'Ulasan berhasil diperbarui.');
    }

    public function destroy(Review $review)
    {
        $review->delete();

        return redirect()->to(route('admin.dashboard').'#moderasi')->with('status', 'Ulasan berhasil dihapus.');
    }
}
