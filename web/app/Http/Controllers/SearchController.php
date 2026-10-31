<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Services\GraphSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class SearchController extends Controller
{
    /** Halaman hasil: film, orang (aktor/sutradara/penulis/komposer), karakter, dan genre dalam satu pencarian. */
    public function index(Request $request, GraphSearch $search)
    {
        $q = Str::limit($request->string('q')->trim()->toString(), 100, '');
        $result = ['terms' => [], 'films' => [], 'people' => [], 'characters' => [], 'genres' => [], 'queries' => [], 'failed' => []];
        $maksud = [];
        $error = null;

        if (mb_strlen($q) >= 2) {
            try {
                $result = $search->search($q);
                if ($this->kosong($result) && $result['failed'] === []) {
                    $maksud = $search->didYouMean($q);
                }
            } catch (Throwable $e) {
                report($e);   // detail teknis hanya ke log, bukan ke pengunjung
                $error = 'Maaf, pencarian sedang tidak dapat diproses. Coba lagi beberapa saat lagi.';
            }

            // Semua query gagal dan tidak ada hasil: itu masalah server, bukan "tidak ditemukan".
            if ($error === null && $this->kosong($result) && count($result['failed']) >= 4) {
                $error = 'Maaf, server data sedang lambat sehingga pencarian belum bisa diselesaikan. Coba lagi beberapa saat lagi.';
            }
        }

        $slugs = array_column($result['films'], 'slug');
        $ratings = $slugs === []
            ? collect()
            : Movie::whereIn('slug', $slugs)->get(['slug', 'avg_rating', 'reviews_count'])->keyBy('slug');

        return view('search', [
            'q' => $q, 'result' => $result, 'ratings' => $ratings, 'maksud' => $maksud,
            'kosong' => mb_strlen($q) >= 2 && $error === null && $result['failed'] === [] && $this->kosong($result), 'error' => $error,
        ]);
    }

    /** JSON untuk saran saat mengetik di kotak pencarian. Murni dari cache, tanpa query ke Fuseki per ketukan. */
    public function suggest(Request $request, GraphSearch $search)
    {
        $q = Str::limit($request->string('q')->trim()->toString(), 60, '');

        try {
            $items = array_map(fn (array $s) => [
                'kind'  => $s['kind'],
                'label' => $s['label'],
                'url'   => match ($s['kind']) {
                    'film'  => route('film.show', $s['key']),
                    'orang' => route('person.show', ['id' => $s['key']]),
                    default => route('home', ['genre' => $s['key']]),
                },
            ], $search->suggest($q));
        } catch (Throwable $e) {
            report($e);
            $items = [];
        }

        return response()->json($items)->header('Cache-Control', 'private, max-age=60');
    }

    private function kosong(array $r): bool
    {
        return ! ($r['films'] || $r['people'] || $r['characters'] || $r['genres']);
    }
}