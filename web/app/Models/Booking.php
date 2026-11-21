<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    protected $guarded = ['id'];
    protected $casts = [
        'show_start' => 'datetime',
        'paid_at' => 'datetime',
        'total_amount' => 'integer',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function seats(): HasMany { return $this->hasMany(BookingSeat::class); }
}
