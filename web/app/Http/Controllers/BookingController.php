<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function create(Request $request)
    {
        $data = $request->validate([
            'screening_iri' => ['required', 'string', 'max:500'],
            'movie_slug' => ['required', 'string', 'max:220'],
            'cinema_id' => ['required', 'string', 'max:100'],
            'show_start' => ['required', 'date'],
            'price' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ]);

        return view('booking.seats', ['screening' => $data]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'screening_iri' => ['required', 'string', 'max:500'],
            'movie_slug' => ['required', 'string', 'max:220'],
            'cinema_id' => ['required', 'string', 'max:100'],
            'show_start' => ['required', 'date'],
            'price' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'seats' => ['required', 'array', 'min:1', 'max:8'],
            'seats.*' => ['required', 'regex:/^[A-H](0[1-9]|1[0-2])$/'],
        ]);
        $seats = array_values(array_unique($data['seats']));
        abort_if(count($seats) !== count($data['seats']), 422, 'Kursi duplikat dipilih.');

        try {
            $booking = DB::transaction(function () use ($request, $data, $seats) {
                $booking = Booking::create([
                    'user_id' => $request->user()->id,
                    'screening_iri' => $data['screening_iri'],
                    'movie_slug' => $data['movie_slug'],
                    'cinema_id' => $data['cinema_id'],
                    'show_start' => $data['show_start'],
                    'seat_count' => count($seats),
                    'total_amount' => count($seats) * (int) ($data['price'] ?? 0),
                    'status' => 'pending',
                ]);
                $booking->seats()->createMany(array_map(fn (string $seat) => [
                    'screening_iri' => $data['screening_iri'], 'seat_code' => $seat,
                ], $seats));
                return $booking;
            });
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
                return back()->withInput()->withErrors(['seats' => 'Salah satu kursi baru saja dipesan pengguna lain.']);
            }
            throw $e;
        }

        return redirect()->route('bookings.show', $booking)->with('status', 'Kursi berhasil ditahan.');
    }

    public function show(Booking $booking)
    {
        abort_unless($booking->user_id === request()->user()->id, 403);
        return view('booking.show', compact('booking'));
    }
    public function pay(
        Request $request,
        Booking $booking,
        \App\Services\MidtransPayment $payment
    ) {
        abort_unless($booking->user_id === $request->user()->id, 403);
        abort_if($booking->status !== 'pending', 422, 'Booking ini tidak menunggu pembayaran.');

        return response()->json([
            'token' => $payment->snapToken($booking),
        ]);
    }
}
