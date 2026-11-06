<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Services\GraphSearch;
use App\Services\ThemeSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class SearchController extends Controller
{
    /** Halaman hasil: film, orang (aktor/sutradara/penulis/komposer), karakter, genre, dan film bertema dalam satu pencarian. */
    public function index(Request $request, GraphSearch $search, ThemeSearch $themes)
    {
        $q = Str::limit($request->string('q')->trim()->toString(), 100, '');
        $result = ['terms' => [], 'films' => [], 'people' => [], 'characters' => [], 'genres' => [], 'queries' => [], 'failed' => [], 'timings' => []];
        $theme = ['concepts' => [], 'films' => [], 'queries' => [], 'failed' => [], 'timings' => []];
        $maksud = [];
        $error = null;

        if (mb_strlen($q) >= 2) {
            try {
                $result = $search->search($q);
            } catch (Throwable $e) {
                report($e);   // detail teknis hanya ke log, bukan ke pengunjung
                $error = 'Maaf, pencarian sedang tidak dapat diproses. Coba lagi beberapa saat lagi.';
            }

            // Semua query utama gagal dan tidak ada hasil: itu masalah server, bukan "tidak ditemukan".
            $gagalUtama = count($result['failed'] ?? []);

            if ($error === null) {
                try {
                    $theme = $themes->search($q);
                    // Film yang sudah tampil di bagian "Film" (cocok judul) tidak diulang di bagian tema.
                    $sudahAda = array_column($result['films'] ?? [], 'slug');
                    $theme['films'] = array_values(array_filter($theme['films'], fn (array $f) => ! in_array($f['slug'], $sudahAda, true)));
                    $result['queries'] = ($result['queries'] ?? []) + ($theme['queries'] ?? []);
                    $result['timings'] = ($result['timings'] ?? []) + ($theme['timings'] ?? []);
                    $result['failed'] = array_merge($result['failed'] ?? [], $theme['failed'] ?? []);
                } catch (Throwable $e) {
                    report($e);   // pencarian tema gagal tidak boleh merusak hasil lainnya
                }
            }

            if ($error === null && $this->kosong($result, $theme) && $result['failed'] === []) {
                try {
                    $maksud = $search->didYouMean($q);
                } catch (Throwable $e) {
                    report($e);
                }
            }

            if ($error === null && $this->kosong($result, $theme) && $gagalUtama >= 4) {
                $error = 'Maaf, server data sedang lambat sehingga pencarian belum bisa diselesaikan. Coba lagi beberapa saat lagi.';
            }
        }

        $slugs = array_merge(array_column($result['films'], 'slug'), array_column($theme['films'], 'slug'));
        $ratings = $slugs === []
            ? collect()
            : Movie::whereIn('slug', $slugs)->get(['slug', 'avg_rating', 'reviews_count'])->keyBy('slug');

        return view('search', [
            'q' => $q, 'result' => $result, 'theme' => $theme, 'ratings' => $ratings, 'maksud' => $maksud,
            'kosong' => mb_strlen($q) >= 2 && $error === null && $result['failed'] === [] && $this->kosong($result, $theme), 'error' => $error,
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

    private function kosong(array $r, array $theme): bool
    {
        return ! ($r['films'] || $r['people'] || $r['characters'] || $r['genres'] || $theme['films']);
    }
}