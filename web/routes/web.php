<?php

use App\Http\Controllers\FilmController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/film/{movie}', [FilmController::class, 'show'])->name('film.show');   // {movie} dicari lewat kolom slug

// IRI resource RDF dibuat bisa dibuka (content negotiation): Accept text/turtle -> data RDF, selain itu 303 ke halaman film
Route::get('/resource/movie/{tmdbId}', [ResourceController::class, 'movie'])->whereNumber('tmdbId')->name('resource.movie');
Route::get('/resource/genre/{genreId}', [ResourceController::class, 'genre'])->whereNumber('genreId')->name('resource.genre');

Route::get('/status', StatusController::class)->name('status');
