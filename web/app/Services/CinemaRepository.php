<?php

namespace App\Services;

use RuntimeException;

class CinemaRepository
{
    /** @var array<int, array<string, mixed>>|null */
    private static ?array $rows = null;

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        $path = base_path('../data/clean/cinemas.csv');

        if (! is_file($path)) {
            throw new RuntimeException(
                'Berkas data/clean/cinemas.csv tidak ditemukan.'
            );
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(
                'Berkas data/clean/cinemas.csv tidak dapat dibuka.'
            );
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);
            throw new RuntimeException(
                'cinemas.csv tidak memiliki header.'
            );
        }

        $rows = [];

        while (($values = fgetcsv($handle)) !== false) {
            if (count($values) !== count($header)) {
                continue;
            }

            $row = array_combine($header, $values);

            $rows[] = [
                'id' => trim((string) ($row['cinema_id'] ?? '')),
                'name' => trim((string) ($row['name'] ?? '')),
                'chain' => trim((string) ($row['brand'] ?? '')),
                'address' => trim((string) ($row['street_address'] ?? '')),
                'city' => trim((string) ($row['address_locality'] ?? '')),
                'district' => trim((string) ($row['district'] ?? '')),
                'region' => trim((string) ($row['address_region'] ?? '')),
                'lat' => (float) ($row['latitude'] ?? 0),
                'lng' => (float) ($row['longitude'] ?? 0),
                'site' => trim((string) ($row['website'] ?? '')),
                'coord' => trim((string) ($row['coordinate_source'] ?? '')),
            ];
        }

        fclose($handle);

        return self::$rows = $rows;
    }
}
