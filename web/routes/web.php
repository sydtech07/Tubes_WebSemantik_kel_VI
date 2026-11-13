<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\CinemaController;
use App\Http\Controllers\FilmController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\WatchlistController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/masuk', [AuthController::class, 'form'])->name('login');
    Route::post('/masuk', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('login.store');
    Route::get('/daftar', [AuthController::class, 'form'])->name('register');
    Route::post('/daftar', [AuthController::class, 'register'])->middleware('throttle:6,1')->name('register.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/keluar', [AuthController::class, 'logout'])->name('logout');
    Route::get('/profil', [ProfileController::class, 'show'])->name('profile');
    Route::patch('/profil', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/film/{movie}/ulasan', [ReviewController::class, 'store'])->middleware('throttle:20,1')->name('reviews.store');
    Route::delete('/ulasan/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');
    Route::put('/film/{movie}/watchlist', [WatchlistController::class, 'store'])->name('watchlist.store');
    Route::delete('/film/{movie}/watchlist', [WatchlistController::class, 'destroy'])->name('watchlist.destroy');

    Route::prefix('admin')->name('admin.')->middleware('admin')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('dashboard');
        Route::get('/ulasan/tambah', [AdminController::class, 'create'])->name('reviews.create');
        Route::post('/ulasan', [AdminController::class, 'store'])->name('reviews.store');
        Route::get('/ulasan/{review}/edit', [AdminController::class, 'edit'])->name('reviews.edit');
        Route::patch('/ulasan/{review}', [AdminController::class, 'update'])->name('reviews.update');
        Route::delete('/ulasan/{review}', [AdminController::class, 'destroy'])->name('reviews.destroy');
    });
});

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

// Peta + daftar bioskop Medan / Sumatera Utara
Route::get('/bioskop', [CinemaController::class, 'index'])->name('cinemas');
Route::post('/bioskop/rute', [CinemaController::class, 'route'])->middleware('throttle:cinema-routes')->name('cinemas.route');

// Route Chatbot
Route::post('/chatbot/send', [ChatbotController::class, 'handleChat'])
    ->middleware('throttle:10,1')
    ->name('chatbot.send');
