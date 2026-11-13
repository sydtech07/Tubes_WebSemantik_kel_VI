<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('cinelog:admin {email}', function () {
    $user = User::where('email', $this->argument('email'))->first();
    if (! $user) {
        $this->error('Email belum terdaftar. Buat akun melalui halaman daftar terlebih dahulu.');

        return 1;
    }
    $user->forceFill(['role' => 'admin'])->save();
    $this->info("Akun {$user->email} sekarang menjadi admin.");
})->purpose('Jadikan akun yang sudah terdaftar sebagai admin');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
