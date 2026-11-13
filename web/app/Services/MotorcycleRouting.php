<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MotorcycleRouting
{
    public function directions(array $origin, array $cinema): array
    {
        $locations = [
            ['lat' => round((float) $origin['lat'], 5), 'lon' => round((float) $origin['lng'], 5)],
            ['lat' => (float) $cinema['lat'], 'lon' => (float) $cinema['lng']],
        ];

        return Cache::remember('cinelog.motorcycle.v1.'.hash('sha256', json_encode($locations)), 600, function () use ($locations) {
            if (! Cache::add('cinelog.motorcycle.interval', true, 2)) {
                $this->fail('Tunggu sebentar sebelum menghitung rute motor berikutnya.', 429);
            }

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Cinelog/1.0 (cinema route planner)',
                    'X-Client-Id' => 'cinelog',
                ])->acceptJson()->connectTimeout(5)->timeout(20)
                    ->get(config('services.valhalla.url').'/route', ['json' => json_encode([
                        'locations' => $locations,
                        'costing' => 'motorcycle',
                        'costing_options' => ['motorcycle' => ['use_highways' => 0, 'use_tolls' => 0]],
                        'units' => 'kilometers',
                        'language' => 'en-US',
                        'shape_format' => 'polyline6',
                    ])]);
            } catch (ConnectionException) {
                $this->fail('Layanan rute motor tidak dapat dihubungi. Coba lagi sebentar.', 503);
            }

            if ($response->status() === 429) {
                $this->fail('Layanan rute motor sedang sibuk. Coba lagi sebentar.', 429);
            }
            if (in_array($response->status(), [400, 404], true)) {
                $this->fail('Rute motor tidak ditemukan. Pilih titik awal di dekat jalan.', 422);
            }
            if (! $response->successful()) {
                $this->fail('Layanan rute motor sedang bermasalah. Coba lagi nanti.', 502);
            }

            $trip = $response->json('trip');
            $legs = data_get($trip, 'legs');
            if (! is_array($legs) || $legs === [] || ! is_numeric(data_get($trip, 'summary.length')) || ! is_numeric(data_get($trip, 'summary.time'))) {
                $this->fail('Data rute motor tidak lengkap. Coba lagi.', 502);
            }

            $coordinates = [];
            $steps = [];
            foreach ($legs as $leg) {
                if (! is_string($leg['shape'] ?? null)) {
                    $this->fail('Jalur rute motor tidak tersedia. Coba lagi.', 502);
                }
                $coordinates = array_merge($coordinates, $this->decode($leg['shape']));
                foreach ($leg['maneuvers'] ?? [] as $maneuver) {
                    $steps[] = [
                        'instruction' => $this->instruction($maneuver),
                        'distance' => (float) ($maneuver['length'] ?? 0) * 1000,
                    ];
                }
            }
            if (count($coordinates) < 2) {
                $this->fail('Jalur rute motor tidak lengkap. Coba lagi.', 502);
            }

            return [
                'geometry' => ['type' => 'LineString', 'coordinates' => $coordinates],
                'distance' => (float) $trip['summary']['length'] * 1000,
                'duration' => (float) $trip['summary']['time'],
                'steps' => $steps,
                'provider' => 'Valhalla',
            ];
        });
    }

    private function decode(string $shape): array
    {
        $coordinates = [];
        $position = [0, 0];
        $index = 0;
        $length = strlen($shape);
        while ($index < $length) {
            foreach ([0, 1] as $axis) {
                $value = 0;
                $shift = 0;
                do {
                    if ($index >= $length || $shift > 35) {
                        $this->fail('Jalur rute motor tidak valid. Coba lagi.', 502);
                    }
                    $byte = ord($shape[$index++]) - 63;
                    if ($byte < 0 || $byte > 63) {
                        $this->fail('Jalur rute motor tidak valid. Coba lagi.', 502);
                    }
                    $value |= ($byte & 31) << $shift;
                    $shift += 5;
                } while ($byte >= 32);
                $position[$axis] += ($value & 1) ? ~($value >> 1) : $value >> 1;
            }
            $lat = $position[0] / 1000000;
            $lng = $position[1] / 1000000;
            if (abs($lat) > 90 || abs($lng) > 180) {
                $this->fail('Koordinat rute motor tidak valid. Coba lagi.', 502);
            }
            $coordinates[] = [$lng, $lat];
        }

        return $coordinates;
    }

    private function instruction(array $step): string
    {
        $action = match ((int) ($step['type'] ?? 0)) {
            1, 2, 3 => 'Mulai perjalanan',
            4, 5, 6 => 'Tiba di tujuan',
            9 => 'Ambil arah sedikit ke kanan',
            10, 18, 20 => 'Belok kanan',
            11 => 'Belok tajam ke kanan',
            12, 13 => 'Putar balik',
            14 => 'Belok tajam ke kiri',
            15, 19, 21 => 'Belok kiri',
            16 => 'Ambil arah sedikit ke kiri',
            23 => 'Tetap di kanan',
            24 => 'Tetap di kiri',
            25 => 'Bergabung dengan jalan',
            26 => 'Masuk bundaran',
            27 => 'Keluar bundaran',
            28 => 'Naik feri',
            29 => 'Keluar dari feri',
            default => 'Lurus',
        };
        $street = trim(implode(', ', $step['street_names'] ?? []));

        return $street !== '' && ! in_array((int) ($step['type'] ?? 0), [4, 5, 6], true) ? $action.' menuju '.$street : $action;
    }

    private function fail(string $message, int $status): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], $status));
    }
}
