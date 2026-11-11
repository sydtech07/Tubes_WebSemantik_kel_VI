<?php

namespace App\Services;

use RuntimeException;

/**
 * Daftar bioskop Sumatera Utara dari resources/data/bioskop-sumut.json.
 * Sumber data statis (bukan API) karena tidak ada API publik resmi untuk lokasi bioskop;
 * untuk menambah/mengubah bioskop cukup edit berkas JSON itu.
 */
class CinemaRepository
{
    /** @var array<int, array<string, mixed>>|null */
    private static ?array $rows = null;

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        if (self::$rows === null) {
            $json = @file_get_contents(resource_path('data/bioskop-sumut.json'));
            if ($json === false) {
                throw new RuntimeException('Berkas resources/data/bioskop-sumut.json tidak ditemukan.');
            }
            self::$rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$rows;
    }
}
