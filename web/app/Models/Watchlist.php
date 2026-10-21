<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Kunci utama gabungan (user_id, movie_id), tanpa kolom id dan tanpa created_at/updated_at. */
class Watchlist extends Model
{
    protected $table = 'watchlists';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = null;
    protected $guarded = [];
}
