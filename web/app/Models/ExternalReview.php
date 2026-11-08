<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/** Ulasan dari sumber luar (TMDB). Terpisah dari Review agar tidak memengaruhi avg_rating. */
class ExternalReview extends Model
{
    protected $table = 'external_reviews';
    public $timestamps = false;

    protected $casts = ['rating' => 'float', 'posted_at' => 'datetime'];

    private static ?GithubFlavoredMarkdownConverter $markdown = null;

    /**
     * Isi ulasan sebagai HTML aman. HTML mentah dari penulis dibuang dan tautan berbahaya ditolak.
     * Konverter dibuat sekali lalu dipakai ulang (Str::markdown() membuat yang baru di setiap panggilan, mahal bila puluhan ulasan).
     */
    public function getContentHtmlAttribute(): string
    {
        self::$markdown ??= new GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);

        return (string) self::$markdown->convert((string) $this->content);
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class, 'movie_id');
    }
}
