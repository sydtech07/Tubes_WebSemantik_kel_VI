<?php

namespace App\Http\Controllers;

use App\Models\CinemaReview;
use App\Services\CinemaRepository;
use Illuminate\Http\Request;

class CinemaReviewController extends Controller
{
    public function store(Request $request, string $cinema, CinemaRepository $cinemas)
    {
        abort_unless(collect($cinemas->all())->contains('id', $cinema), 404, 'Bioskop tidak ditemukan.');

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
        ]);

        CinemaReview::updateOrCreate(
            ['user_id' => $request->user()->id, 'cinema_id' => $cinema],
            ['rating' => $data['rating']]
        );

        return redirect()->to(route('cinemas').'#cinema-'.rawurlencode($cinema))
            ->with('status', 'Rating bioskop berhasil disimpan.');
    }

    public function destroy(Request $request, string $cinema, CinemaRepository $cinemas)
    {
        abort_unless(collect($cinemas->all())->contains('id', $cinema), 404, 'Bioskop tidak ditemukan.');

        CinemaReview::where('user_id', $request->user()->id)
            ->where('cinema_id', $cinema)
            ->delete();

        return redirect()->to(route('cinemas').'#cinema-'.rawurlencode($cinema))
            ->with('status', 'Rating bioskop berhasil dihapus.');
    }
}
