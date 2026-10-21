<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Setiap INSERT/UPDATE/DELETE pada tabel ini memicu trigger yang memperbarui
 * movies.reviews_count dan movies.avg_rating (lihat database/sql/02_routines.sql).
 */
class Review extends Model
{
    protected $table = 'reviews';

    protected $fillable = ['user_id', 'movie_id', 'rating', 'title', 'body', 'contains_spoiler'];

    protected $casts = ['contains_spoiler' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class, 'movie_id');
    }
}
