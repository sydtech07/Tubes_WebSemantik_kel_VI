<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Services\MovieRepository;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Throwable;

class HomeController extends Controller
{
    /**
     * Katalog + pencarian + filter genre + urutan.
     * Daftar film datang dari Fuseki (SPARQL); rating dan jumlah ulasan dari MariaDB (dikelola trigger).
     */
    public function index(Request $request, MovieRepository $repo)
    {
        $q     = Str::limit($request->string('q')->trim()->toString(), 100, '');
        $genre = $request->integer('genre') ?: null;
        $sort  = $request->string('sort')->toString();
        $sort  = isset(MovieRepository::SORTS[$sort]) ? $sort : 'populer';
        $page  = max(1, $request->integer('page', 1));
        $size  = (int) config('cinelog.page_size', 24);

        // Hero carousel hanya di beranda polos (tanpa pencarian/filter, halaman 1)
        $tampilkanHero = $q === '' && ! $genre && $page === 1;

        try {
            $result   = $repo->catalog($q, $genre, $sort, $page, $size);
            $genres   = $repo->genres();
            $featured = $tampilkanHero ? $repo->featured(5) : [];
            $error    = null;
        } catch (Throwable $e) {
            report($e);
            $result   = ['items' => [], 'total' => 0];
            $genres   = [];
            $featured = [];
            $error    = 'Data film tidak dapat diambil dari Fuseki: ' . $e->getMessage();
        }

        $slugs   = array_column($result['items'], 'slug');
        $ratings = $slugs === []
            ? collect()
            : Movie::whereIn('slug', $slugs)->get(['slug', 'avg_rating', 'reviews_count'])->keyBy('slug');

        $paginator = new LengthAwarePaginator($result['items'], $result['total'], $size, $page, [
            'path'  => $request->url(),
            'query' => $request->query(),
        ]);

        return view('home', [
            'items'     => $result['items'],
            'total'     => $result['total'],
            'paginator' => $paginator,
            'ratings'   => $ratings,
            'genres'    => $genres,
            'featured'  => $featured,
            'q'         => $q,
            'genre'     => $genre,
            'sort'      => $sort,
            'error'     => $error,
        ]);
    }
}
