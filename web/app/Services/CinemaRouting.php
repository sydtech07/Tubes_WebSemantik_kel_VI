<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CinemaRouting
{
    public function directions(array $origin, array $cinema, string $profile): array
    {
        if ($profile === 'motorcycle') {
            return app(MotorcycleRouting::class)->directions($origin, $cinema);
        }

        $key = config('services.openrouteservice.key');
        if (! $key) {
            $this->fail('Layanan rute belum dikonfigurasi.', 503);
        }

        $coordinates = [
            [round((float) $origin['lng'], 5), round((float) $origin['lat'], 5)],
            [(float) $cinema['lng'], (float) $cinema['lat']],
        ];
        $cacheKey = 'cinelog.route.v2.'.hash('sha256', json_encode([$profile, $coordinates]));

        return Cache::remember($cacheKey, 600, function () use ($key, $coordinates, $profile) {
            try {
                $response = Http::withHeaders(['Authorization' => $key])->accept('application/geo+json')
                    ->connectTimeout(5)->timeout(20)
                    ->get(config('services.openrouteservice.url').'/v2/directions/'.$profile, [
                        'start' => implode(',', $coordinates[0]),
                        'end' => implode(',', $coordinates[1]),
                    ]);
            } catch (ConnectionException) {
                $this->fail('Layanan rute tidak dapat dihubungi. Coba lagi sebentar.', 503);
            }

            if ($response->status() === 429) {
                $this->fail('Batas permintaan layanan rute tercapai. Coba lagi nanti.', 429);
            }
            if (in_array($response->status(), [401, 403], true)) {
                $this->fail('Akses layanan rute belum tersedia. Periksa key dan kuota layanan.', 503);
            }
            if (in_array($response->status(), [400, 404], true)) {
                $this->fail('Rute tidak ditemukan. Pilih titik awal di dekat jalan yang dapat dilalui.', 422);
            }
            if (! $response->successful()) {
                $this->fail('Layanan rute sedang bermasalah. Coba lagi nanti.', 502);
            }

            $feature = $response->json('features.0');
            $geometry = data_get($feature, 'geometry');
            $summary = data_get($feature, 'properties.summary');
            if (data_get($geometry, 'type') !== 'LineString'
                || ! is_array(data_get($geometry, 'coordinates'))
                || count($geometry['coordinates']) < 2
                || ! is_numeric(data_get($summary, 'distance'))
                || ! is_numeric(data_get($summary, 'duration'))) {
                $this->fail('Data rute tidak lengkap. Coba titik awal lainnya.', 502);
            }

            $steps = collect(data_get($feature, 'properties.segments', []))
                ->flatMap(fn ($segment) => $segment['steps'] ?? [])
                ->map(fn ($step) => [
                    'instruction' => $this->instruction($step),
                    'distance' => (float) ($step['distance'] ?? 0),
                ])->values()->all();

            return [
                'geometry' => $geometry,
                'distance' => (float) $summary['distance'],
                'duration' => (float) $summary['duration'],
                'steps' => $steps,
                'provider' => 'OpenRouteService',
            ];
        });
    }

    private function instruction(array $step): string
    {
        $action = match ((int) ($step['type'] ?? -1)) {
            0 => 'Belok kiri',
            1 => 'Belok kanan',
            2 => 'Belok tajam ke kiri',
            3 => 'Belok tajam ke kanan',
            4 => 'Ambil arah sedikit ke kiri',
            5 => 'Ambil arah sedikit ke kanan',
            6 => 'Lurus',
            7 => 'Masuk bundaran',
            8 => 'Keluar bundaran',
            9 => 'Putar balik',
            10 => 'Tiba di tujuan',
            11 => 'Mulai perjalanan',
            12 => 'Tetap di kiri',
            13 => 'Tetap di kanan',
            default => 'Lanjutkan perjalanan',
        };
        if ((int) ($step['type'] ?? -1) === 7 && ! empty($step['exit_number'])) {
            $action .= ', ambil jalan keluar ke-'.(int) $step['exit_number'];
        }
        $name = trim((string) ($step['name'] ?? ''));

        return $name !== '' && $name !== '-' && (int) ($step['type'] ?? -1) !== 10
            ? $action.' menuju '.$name
            : $action;
    }

    private function fail(string $message, int $status): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], $status));
    }
}
