<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    protected $table = 'people';   // bentuk jamak 'person' tidak otomatis menjadi 'people' di semua versi; dibuat eksplisit
    public $timestamps = false;
    protected $guarded = ['id'];

    public function credits(): HasMany
    {
        return $this->hasMany(MovieCredit::class, 'person_id');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->profile_path
            ? config('cinelog.tmdb_image_base') . 'w185' . $this->profile_path
            : null;
    }
}
