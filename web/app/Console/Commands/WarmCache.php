<?php

namespace App\Console\Commands;

use App\Models\Movie;
use App\Services\GraphSearch;
use App\Services\MovieRepository;
use Illuminate\Console\Command;
use Throwable;

/** Mengisi cache lebih dulu agar kunjungan PERTAMA ke tiap halaman film sama cepatnya dengan kunjungan berikutnya. */
class WarmCache extends Command
{
    protected $signature = 'cinelog:warm {--limit=0 : hanya N film pertama (0 = semua)}';

    protected $description = 'Hangatkan cache: daftar genre, film unggulan, kosakata pencarian, dan detail setiap film dari Fuseki.';

    public function handle(MovieRepository $repo, GraphSearch $graph): int
    {
        try {
            $repo->genres();
            $repo->featured(5);
            $graph->vocabulary();
            $this->info('Genre, film unggulan, dan kosakata pencarian siap.');
        } catch (Throwable $e) {
            $this->error('Fuseki tidak dapat dihubungi: ' . $e->getMessage());

            return self::FAILURE;
        }

        $query = Movie::query()->orderBy('id');
        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }
        $slugs = $query->pluck('slug');

        $bar = $this->output->createProgressBar($slugs->count());
        $bar->start();
        $gagal = 0;
        foreach ($slugs as $slug) {
            try {
                $repo->detail($slug);
            } catch (Throwable $e) {
                $gagal++;
            }
            $bar->advance();
        }
        $bar->finish();

        $this->newLine(2);
        $this->info(($slugs->count() - $gagal) . ' halaman film dihangatkan' . ($gagal ? ", {$gagal} gagal." : '.'));

        return $gagal ? self::FAILURE : self::SUCCESS;
    }
}
