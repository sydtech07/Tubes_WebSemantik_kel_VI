<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use App\Services\MovieRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CommunityFeaturesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('user');
            $table->rememberToken();
            $table->timestamps();
        });
        $migration = require database_path('migrations/2026_11_13_000001_add_last_seen_at_to_users.php');
        $migration->up();
        Schema::create('movies', function (Blueprint $table) {
            $table->id();
            $table->integer('tmdb_id')->unique();
            $table->string('title');
            $table->string('slug')->unique();
            $table->date('release_date');
            $table->string('poster_path')->nullable();
            $table->integer('reviews_count')->default(0);
            $table->decimal('avg_rating', 4, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->integer('rating');
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->boolean('contains_spoiler')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'movie_id']);
        });
        Schema::create('watchlists', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->timestamp('added_at')->useCurrent();
            $table->primary(['user_id', 'movie_id']);
        });
        Schema::create('external_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id');
            $table->string('source')->default('tmdb');
            $table->string('author');
            $table->decimal('rating', 3, 1)->nullable();
            $table->text('content');
            $table->string('source_url')->nullable();
            $table->timestamp('posted_at')->nullable();
        });
    }

    private function user(string $email = 'penonton@example.test', string $role = 'user'): User
    {
        $user = User::create(['name' => 'Penonton', 'email' => $email, 'password' => 'rahasia123']);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function movie(string $slug = 'film-contoh'): Movie
    {
        return Movie::create(['tmdb_id' => random_int(1, 10000000), 'title' => 'Film Contoh', 'slug' => $slug, 'release_date' => '2026-06-01']);
    }

    private function mockFilm(Movie $movie): void
    {
        $this->mock(MovieRepository::class, function ($mock) use ($movie) {
            $mock->shouldReceive('detail')->with($movie->slug)->andReturn([
                'title' => $movie->title, 'altTitle' => null, 'date' => '2026-06-01', 'url' => url('/film/'.$movie->slug),
                'iri' => 'http://example.test/resource/movie/1', 'description' => 'Sinopsis film.',
                'poster' => null, 'backdrop' => null, 'vote' => null, 'votes' => 0, 'runtime' => 90,
                'genres' => [], 'cast' => [], 'directors' => [], 'writers' => [], 'composers' => [],
                'descId' => 'Sinopsis film.', 'descEn' => null, 'lang' => 'id', 'tagline' => null,
                'imdb' => null, 'wikidata' => null, 'budget' => 0, 'revenue' => 0,
            ]);
        });
    }

    public function test_guest_can_view_film_but_interactions_require_login(): void
    {
        $movie = $this->movie();
        $this->mockFilm($movie);
        $this->get(route('film.show', $movie))->assertOk()->assertSee('Masuk untuk memberi ulasan');
        $this->put(route('reviews.store', $movie), ['rating' => 8])->assertRedirect(route('login'));
        $this->put(route('watchlist.store', $movie))->assertRedirect(route('login'));
        $this->get(route('profile'))->assertRedirect(route('login'));
        $this->assertDatabaseCount('reviews', 0);
        $this->assertDatabaseCount('watchlists', 0);
    }

    public function test_registration_hashes_password_ignores_admin_role_and_returns_to_film(): void
    {
        $this->get('/daftar?next='.urlencode('/film/film-contoh#beri-ulasan'))->assertOk();
        $this->post(route('register.store'), [
            'name' => 'Ayu', 'email' => 'ayu@example.test', 'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123', 'role' => 'admin',
        ])->assertRedirect('/film/film-contoh#beri-ulasan');
        $user = User::first();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('rahasia123', $user->password));
        $this->assertSame('user', $user->role);
    }

    public function test_registration_validates_duplicate_email_and_password_confirmation(): void
    {
        $this->user();
        $this->post(route('register.store'), ['name' => 'Nama', 'email' => 'penonton@example.test', 'password' => 'abc', 'password_confirmation' => 'xyz'])
            ->assertSessionHasErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_rejects_wrong_password_and_logout_ends_session(): void
    {
        $user = $this->user();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'salah'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'rahasia123'])->assertRedirect(route('profile'));
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'))->assertRedirect(route('home'));
        $this->assertGuest();
    }

    public function test_login_attempts_are_throttled(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post(route('login.store'), ['email' => 'nobody@example.test', 'password' => 'salah'])->assertSessionHasErrors('email');
        }
        $this->post(route('login.store'), ['email' => 'nobody@example.test', 'password' => 'salah'])->assertStatus(429);
    }

    public function test_external_return_url_is_ignored(): void
    {
        $user = $this->user();
        $this->get('/masuk?next='.urlencode('//example.org/film/abc'))->assertOk();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'rahasia123'])->assertRedirect(route('profile'));
    }

    public function test_rating_can_be_created_updated_without_duplicates_and_deleted(): void
    {
        $user = $this->user();
        $movie = $this->movie();
        $this->actingAs($user)->put(route('reviews.store', $movie), ['rating' => 8])->assertSessionHasNoErrors();
        $this->put(route('reviews.store', $movie), ['rating' => 9, 'body' => 'Bagus!', 'contains_spoiler' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('reviews', 1);
        $this->assertDatabaseHas('reviews', ['user_id' => $user->id, 'movie_id' => $movie->id, 'rating' => 9, 'body' => 'Bagus!', 'contains_spoiler' => 1]);
        $this->delete(route('reviews.destroy', Review::first()))->assertRedirect();
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_invalid_rating_and_deleting_another_users_review_are_rejected(): void
    {
        $owner = $this->user();
        $other = $this->user('other@example.test');
        $movie = $this->movie();
        $review = $owner->reviews()->create(['movie_id' => $movie->id, 'rating' => 7]);
        $this->actingAs($other)->put(route('reviews.store', $movie), ['rating' => 11])->assertSessionHasErrors('rating');
        $this->put(route('reviews.store', $movie), ['rating' => '8.5'])->assertSessionHasErrors('rating');
        $this->delete(route('reviews.destroy', $review))->assertForbidden();
        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_deleting_review_from_profile_returns_to_profile(): void
    {
        $user = $this->user();
        $movie = $this->movie();
        $review = $user->reviews()->create(['movie_id' => $movie->id, 'rating' => 8]);
        $this->actingAs($user)->get(route('profile'))->assertOk()->assertSee('name="return_to" value="profile"', false);
        $this->delete(route('reviews.destroy', $review), ['return_to' => 'profile'])
            ->assertRedirect(route('profile').'#rating-saya');
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_deleting_review_from_film_ignores_untrusted_return_url(): void
    {
        $user = $this->user();
        $movie = $this->movie();
        $review = $user->reviews()->create(['movie_id' => $movie->id, 'rating' => 8]);
        $this->actingAs($user)->delete(route('reviews.destroy', $review), ['return_to' => 'https://example.org'])
            ->assertRedirect(route('film.show', $movie).'#ulasan-pengguna');
    }

    public function test_catalog_renders_compact_dual_genre_filter_and_preserves_selection(): void
    {
        $this->mock(MovieRepository::class, function ($mock) {
            $mock->shouldReceive('catalog')->once()->with('', [10749, 28], 'terbaru', 1, 24)->andReturn(['items' => [], 'total' => 0]);
            $mock->shouldReceive('genres')->once()->andReturn([
                ['id' => 10749, 'label' => 'Romance', 'n' => 55],
                ['id' => 28, 'label' => 'Action', 'n' => 129],
            ]);
            $mock->shouldNotReceive('featured');
        });
        $response = $this->get('/?genre%5B%5D=10749&genre%5B%5D=28&sort=terbaru');
        $response->assertOk()->assertSee('Genre Romance + Action')->assertSee('Romance (55 film)')->assertSee('Action (129 film)')
            ->assertSee('id="genre-first"', false)->assertSee('id="genre-second"', false)
            ->assertDontSee('Produk ini memakai API TMDB tetapi tidak didukung atau disertifikasi oleh TMDB.');
        $this->assertSame([10749, 28], $response->viewData('genreIds'));
    }

    public function test_catalog_accepts_existing_single_genre_links_and_keeps_filters_on_next_page(): void
    {
        $this->mock(MovieRepository::class, function ($mock) {
            $mock->shouldReceive('catalog')->once()->with('cinta', [10749], 'populer', 1, 24)->andReturn(['items' => [], 'total' => 30]);
            $mock->shouldReceive('genres')->once()->andReturn([['id' => 10749, 'label' => 'Romance', 'n' => 55]]);
            $mock->shouldNotReceive('featured');
        });
        $response = $this->get('/?genre=10749&q=cinta');
        $response->assertOk();
        $paginator = $response->viewData('paginator');
        parse_str(parse_url($paginator->url(2), PHP_URL_QUERY), $query);
        $this->assertSame(['10749'], $query['genre']);
        $this->assertSame('cinta', $query['q']);
        $this->assertSame('2', $query['page']);
    }

    public function test_watchlist_is_idempotent_and_private(): void
    {
        $user = $this->user();
        $other = $this->user('other@example.test');
        $movie = $this->movie();
        $this->actingAs($user)->put(route('watchlist.store', $movie))->assertSessionHasNoErrors();
        $this->put(route('watchlist.store', $movie))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('watchlists', 1);
        $this->actingAs($other)->get(route('profile'))->assertOk()->assertSee('Daftar tontonanmu dimulai di sini');
        $this->delete(route('watchlist.destroy', $movie))->assertRedirect();
        $this->assertDatabaseCount('watchlists', 1);
        $this->actingAs($user)->get(route('profile'))->assertOk()->assertSee('Film Contoh');
        $this->delete(route('watchlist.destroy', $movie))->assertRedirect();
        $this->assertDatabaseCount('watchlists', 0);
    }

    public function test_profile_updates_cannot_promote_user_or_take_another_email(): void
    {
        $user = $this->user();
        $other = $this->user('other@example.test');
        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Nama Baru', 'email' => $other->email])->assertSessionHasErrors('email');
        $this->patch(route('profile.update'), ['name' => 'Nama Baru', 'email' => 'baru@example.test', 'role' => 'admin'])->assertRedirect(route('profile'));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Nama Baru', 'email' => 'baru@example.test', 'role' => 'user']);
        $this->assertNotNull($user->fresh()->last_seen_at);
    }

    public function test_non_admin_cannot_access_any_admin_action(): void
    {
        $user = $this->user();
        $movie = $this->movie();
        $review = $user->reviews()->create(['movie_id' => $movie->id, 'rating' => 8]);
        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
        $this->get(route('admin.reviews.create'))->assertForbidden();
        $this->get(route('admin.reviews.edit', $review))->assertForbidden();
        $this->post(route('admin.reviews.store'), ['movie_id' => $movie->id, 'rating' => 5])->assertForbidden();
        $this->patch(route('admin.reviews.update', $review), ['rating' => 5])->assertForbidden();
        $this->delete(route('admin.reviews.destroy', $review))->assertForbidden();
    }

    public function test_admin_can_view_stats_and_create_read_update_delete_reviews(): void
    {
        $admin = $this->user('admin@example.test', 'admin');
        $user = $this->user();
        $movie = $this->movie();
        $review = $user->reviews()->create(['movie_id' => $movie->id, 'rating' => 8, 'body' => '<script>alert(1)</script>']);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Distribusi rating')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $this->get(route('admin.reviews.create'))->assertOk();
        $this->get(route('admin.reviews.edit', $review))->assertOk();
        $this->post(route('admin.reviews.store'), ['movie_id' => $movie->id, 'rating' => 10, 'user_id' => $user->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('reviews', ['user_id' => $admin->id, 'rating' => 10]);
        $this->patch(route('admin.reviews.update', $review), ['rating' => 7, 'body' => 'Diperbarui', 'user_id' => $admin->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'user_id' => $user->id, 'rating' => 7]);
        $this->delete(route('admin.reviews.destroy', $review))->assertRedirect();
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_film_renders_own_review_and_hides_spoiler_in_details(): void
    {
        $user = $this->user();
        $movie = $this->movie();
        $user->reviews()->create(['movie_id' => $movie->id, 'rating' => 8, 'body' => '<img src=x onerror=alert(1)>', 'contains_spoiler' => true]);
        $this->mockFilm($movie);
        $this->actingAs($user)->get(route('film.show', $movie))->assertOk()->assertSee('Perbarui rating dan ulasanmu')->assertSee('Mengandung spoiler')->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    public function test_admin_command_only_promotes_existing_accounts(): void
    {
        $user = $this->user();
        $this->artisan('cinelog:admin', ['email' => 'missing@example.test'])->assertExitCode(1);
        $this->artisan('cinelog:admin', ['email' => $user->email])->assertSuccessful();
        $this->assertTrue($user->fresh()->isAdmin());
    }

    public function test_viewer_reviews_mix_sources_in_date_order_and_keep_local_ownership(): void
    {
        $user = $this->user();
        $movie = $this->movie();
        $review = $user->reviews()->create(['movie_id' => $movie->id, 'rating' => 8, 'body' => 'Ulasan komunitas']);
        $review->forceFill(['updated_at' => '2026-01-01 12:00:00'])->save();
        DB::table('external_reviews')->insert([
            'movie_id' => $movie->id, 'author' => 'Penonton TMDB', 'rating' => 6.5,
            'content' => 'Ulasan impor', 'posted_at' => '2026-02-01 12:00:00',
        ]);
        $this->mockFilm($movie);
        $response = $this->actingAs($user)->get(route('film.show', $movie));
        $response->assertOk()->assertSee('Ulasan penonton')->assertDontSee('Ulasan dari TMDB')
            ->assertSeeInOrder(['data-review-source="tmdb"', 'data-review-source="cinelog"'], false)
            ->assertSee('Edit ulasan')->assertSee('Rating komunitas CINÉLOG dihitung terpisah');
        $items = $response->viewData('viewerReviews');
        $this->assertSame(2, $items->total());
        $this->assertSame(['tmdb', 'cinelog'], $items->pluck('source')->all());
        $this->assertSame('Penonton TMDB', $items[0]['review']->author);
        $this->assertSame('Ulasan komunitas', $items[1]['review']->body);
        $this->assertDatabaseCount('reviews', 1);
        $this->assertSame(0.0, $movie->fresh()->avg_rating);
    }

    public function test_source_filters_and_rating_order_apply_to_entire_review_feed(): void
    {
        $user = $this->user();
        $movie = $this->movie();
        $user->reviews()->create(['movie_id' => $movie->id, 'rating' => 8]);
        DB::table('external_reviews')->insert([
            ['movie_id' => $movie->id, 'author' => 'TMDB A', 'rating' => 9.5, 'content' => 'Rating tinggi', 'posted_at' => null],
            ['movie_id' => $movie->id, 'author' => 'TMDB B', 'rating' => 2, 'content' => 'Rating rendah', 'posted_at' => null],
            ['movie_id' => $movie->id, 'author' => 'TMDB C', 'rating' => null, 'content' => 'Tanpa rating', 'posted_at' => null],
        ]);
        $this->mockFilm($movie);
        $local = $this->get(route('film.show', ['movie' => $movie, 'sumber' => 'cinelog']))->assertOk();
        $this->assertSame(1, $local->viewData('viewerReviews')->total());
        $local->assertDontSee('data-review-source="tmdb"', false);
        $external = $this->get(route('film.show', ['movie' => $movie, 'sumber' => 'tmdb', 'urutan_ulasan' => 'high']))->assertOk();
        $this->assertSame(['TMDB A', 'TMDB B', 'TMDB C'], $external->viewData('viewerReviews')->pluck('review.author')->all());
        $external->assertDontSee('data-review-source="cinelog"', false)->assertDontSee('Edit ulasan');
        $low = $this->get(route('film.show', ['movie' => $movie, 'urutan_ulasan' => 'low']))->assertOk();
        $this->assertSame([2.0, 8, 9.5, null], $low->viewData('viewerReviews')->pluck('review.rating')->all());
        $fallback = $this->get(route('film.show', ['movie' => $movie, 'sumber' => 'invalid', 'urutan_ulasan' => 'invalid']))->assertOk();
        $this->assertSame('all', $fallback->viewData('reviewSource'));
        $this->assertSame('new', $fallback->viewData('reviewSort'));
    }

    public function test_external_reviews_are_paginated_beyond_sixty_and_filters_survive_pagination(): void
    {
        $movie = $this->movie();
        $other = $this->movie('film-lain');
        $rows = [];
        for ($index = 1; $index <= 65; $index++) {
            $rows[] = ['movie_id' => $movie->id, 'author' => 'Penonton '.$index, 'rating' => 7, 'content' => 'Ulasan '.$index, 'posted_at' => '2026-01-01 12:00:00'];
        }
        $rows[] = ['movie_id' => $other->id, 'author' => 'Film lain', 'rating' => 8, 'content' => 'Bukan film ini', 'posted_at' => null];
        DB::table('external_reviews')->insert($rows);
        $this->mockFilm($movie);
        $response = $this->get(route('film.show', ['movie' => $movie, 'sumber' => 'tmdb', 'urutan_ulasan' => 'high', 'ulasan_page' => 7]))->assertOk();
        $page = $response->viewData('viewerReviews');
        $this->assertSame(65, $page->total());
        $this->assertCount(5, $page);
        $response->assertSee('sumber=tmdb', false)->assertSee('urutan_ulasan=high', false)->assertDontSee('Bukan film ini');
    }

    public function test_external_markdown_is_sanitized_and_unsafe_source_links_are_hidden(): void
    {
        $movie = $this->movie();
        DB::table('external_reviews')->insert([
            'movie_id' => $movie->id, 'author' => 'Penonton TMDB', 'rating' => null,
            'content' => '**Bagus** <script>alert(1)</script> [tautan](javascript:alert(1))',
            'source_url' => 'javascript:alert(1)',
        ]);
        $this->mockFilm($movie);
        $response = $this->get(route('film.show', $movie))->assertOk();
        $response->assertSee('<strong>Bagus</strong>', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('href="javascript:', false)->assertDontSee('Lihat di TMDB');
    }
}
