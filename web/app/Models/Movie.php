<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabel 'movies' dibuat oleh database/sql/01_schema.sql (BUKAN oleh migration Laravel).
 * Kolom reviews_count dan avg_rating dikelola TRIGGER database, jadi tidak boleh diisi mass-assignment.
 */
class Movie extends Model
{
    protected $table = 'movies';

    protected $guarded = ['id', 'reviews_count', 'avg_rating'];

    protected $casts = [
        'release_date'      => 'date',
        'tmdb_popularity'   => 'float',
        'tmdb_vote_average' => 'float',
        'avg_rating'        => 'float',
    ];

    /** URL /film/{slug} memakai kolom slug, bukan id. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'movie_genres', 'movie_id', 'genre_id');
    }

    public function credits(): HasMany
    {
        return $this->hasMany(MovieCredit::class, 'movie_id');
    }

    public function cast(): HasMany
    {
        return $this->credits()->where('credit_type', 'cast')->orderBy('billing_order');
    }

    public function crew(): HasMany
    {
        return $this->credits()->where('credit_type', 'crew');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'movie_id');
    }

    /** URL poster ukuran sedang, atau null jika film tidak punya poster. */
    public function getPosterUrlAttribute(): ?string
    {
        return $this->poster_path
            ? config('cinelog.tmdb_image_base') . 'w342' . $this->poster_path
            : null;
    }
}
