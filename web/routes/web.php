<?php

use App\Http\Controllers\FilmController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/film/{movie}', [FilmController::class, 'show'])->name('film.show');   // {movie} dicari lewat kolom slug
Route::get('/status', StatusController::class)->name('status');
