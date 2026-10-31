<?php

use App\Http\Controllers\FilmController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\SearchController;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/film/{movie}', [FilmController::class, 'show'])->name('film.show');   // {movie} dicari lewat kolom slug

// Pencarian lintas film, orang, karakter, dan genre + saran ketik (JSON) + halaman orang
Route::get('/cari', [SearchController::class, 'index'])->name('search');
Route::get('/api/saran', [SearchController::class, 'suggest'])->name('search.suggest');
Route::get('/orang/{id}', [PersonController::class, 'show'])->whereNumber('id')->name('person.show');
// IRI resource RDF dibuat bisa dibuka (content negotiation): Accept text/turtle -> data RDF, selain itu 303 ke halaman film
Route::get('/resource/movie/{tmdbId}', [ResourceController::class, 'movie'])->whereNumber('tmdbId')->name('resource.movie');
Route::get('/resource/person/{personId}', [ResourceController::class, 'person'])->whereNumber('personId')->name('resource.person');
Route::get('/resource/genre/{genreId}', [ResourceController::class, 'genre'])->whereNumber('genreId')->name('resource.genre');

Route::get('/status', StatusController::class)->name('status');

