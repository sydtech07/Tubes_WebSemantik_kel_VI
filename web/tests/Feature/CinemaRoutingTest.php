<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CinemaRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CinemaRoutingTest extends TestCase
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
        $userMigration = require database_path('migrations/2026_11_13_000001_add_last_seen_at_to_users.php');
        $userMigration->up();
        $migration = require database_path('migrations/2026_11_21_030000_create_cinema_reviews_table.php');
        $migration->up();
        config(['services.openrouteservice.key' => 'routing-test-key']);
        Http::preventStrayRequests();
    }

    private function payload(array $changes = []): array
    {
        return array_replace_recursive([
            // Dataset bioskop terbaru memakai ID sumber yang baru; gunakan fixture
            // yang benar-benar ada pada cinemas.csv / dataset RDF.
            'cinema_id' => 'cgv-001',
            'origin' => ['lat' => 3.585, 'lng' => 98.672],
            'profile' => 'driving-car',
        ], $changes);
    }

    private function routeResponse(): array
    {
        return [
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'LineString', 'coordinates' => [[98.672, 3.585], [98.675, 3.589], [98.68142, 3.59191]]],
                'properties' => [
                    'summary' => ['distance' => 1850.5, 'duration' => 320.2],
                    'segments' => [[
                        'steps' => [
                            ['type' => 11, 'name' => 'Jalan Merdeka', 'distance' => 100],
                            ['type' => 1, 'name' => 'Jalan Jawa', 'distance' => 1750.5],
                            ['type' => 10, 'name' => '-', 'distance' => 0],
                        ],
                    ]],
                ],
            ]],
            'metadata' => ['query' => ['api_key' => 'must-not-leak']],
        ];
    }

    public function test_public_cinema_page_renders_internal_routing_controls_without_key(): void
    {
        $this->get(route('cinemas'))->assertOk()
            ->assertSee('Rute perjalanan')->assertSee('Pilih titik awal di peta')->assertSee('<option value="motorcycle">Motor</option>', false)
            ->assertSee('koordinat titik awal dan bioskop dikirim ke OpenRouteService')
            ->assertSee('https://tile.openstreetmap.org/', false)->assertDontSee('basemaps.cartocdn.com', false)
            ->assertDontSee('google.com/maps/dir', false)->assertSee('google.com/maps/search/', false)
            ->assertSee('Belum ada rating pengguna')->assertDontSee('routing-test-key');
        Http::assertNothingSent();
    }

    public function test_user_can_create_update_and_delete_their_cinema_rating(): void
    {
        $user = User::create(['name' => 'Penonton', 'email' => 'penonton@example.test', 'password' => 'rahasia123']);

        $this->put(route('cinemas.reviews.store', 'cgv-001'), ['rating' => 4])
            ->assertRedirect(route('login'));
        $this->actingAs($user)->put(route('cinemas.reviews.store', 'cgv-001'), ['rating' => 4])
            ->assertRedirect(route('cinemas').'#cinema-cgv-001');
        $this->put(route('cinemas.reviews.store', 'cgv-001'), ['rating' => 5])
            ->assertRedirect(route('cinemas').'#cinema-cgv-001');
        $this->assertDatabaseCount('cinema_reviews', 1);
        $this->assertDatabaseHas('cinema_reviews', ['user_id' => $user->id, 'cinema_id' => 'cgv-001', 'rating' => 5]);

        $response = $this->get(route('cinemas'))->assertOk();
        $this->assertSame(5, collect($response->viewData('cinemas'))->firstWhere('id', 'cgv-001')['user_rating']);
        $this->assertSame(5.0, collect($response->viewData('cinemas'))->firstWhere('id', 'cgv-001')['average_rating']);

        $this->delete(route('cinemas.reviews.destroy', 'cgv-001'))->assertRedirect(route('cinemas').'#cinema-cgv-001');
        $this->assertDatabaseCount('cinema_reviews', 0);
    }

    public function test_cinema_ratings_validate_score_and_reject_unknown_locations(): void
    {
        $user = User::create(['name' => 'Penonton', 'email' => 'penonton@example.test', 'password' => 'rahasia123']);
        $this->actingAs($user)->put(route('cinemas.reviews.store', 'cgv-001'), ['rating' => 6])->assertSessionHasErrors('rating');
        $this->put(route('cinemas.reviews.store', 'cgv-001'), ['rating' => '3.5'])->assertSessionHasErrors('rating');
        $this->put(route('cinemas.reviews.store', 'bioskop-tidak-ada'), ['rating' => 4])->assertNotFound();
        $this->assertDatabaseCount('cinema_reviews', 0);
    }

    public function test_route_uses_server_side_key_correct_coordinates_and_safe_response(): void
    {
        Http::fake(['api.heigit.org/*' => Http::response($this->routeResponse())]);
        $response = $this->postJson(route('cinemas.route'), $this->payload());
        $response->assertOk()->assertJsonPath('geometry.type', 'LineString')
            ->assertJsonPath('distance', 1850.5)->assertJsonPath('duration', 320.2)
            ->assertJsonPath('steps.1.instruction', 'Belok kanan menuju Jalan Jawa')
            ->assertJsonPath('steps.2.instruction', 'Tiba di tujuan')
            ->assertDontSee('must-not-leak')->assertDontSee('routing-test-key')->assertJsonMissingPath('metadata');
        $cinema = collect(app(CinemaRepository::class)->all())->firstWhere('id', 'cgv-001');
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && parse_url($request->url(), PHP_URL_PATH) === '/openrouteservice/v2/directions/driving-car'
            && $request->hasHeader('Authorization', 'routing-test-key')
            && $request->hasHeader('Accept', 'application/geo+json')
            && $request['start'] === '98.672,3.585'
            && $request['end'] === $cinema['lng'].','.$cinema['lat']);
    }

    public function test_repeated_route_is_cached_and_changing_transport_uses_its_own_route(): void
    {
        Http::fake(['api.heigit.org/*' => Http::response($this->routeResponse())]);
        $this->postJson(route('cinemas.route'), $this->payload())->assertOk();
        $this->postJson(route('cinemas.route'), $this->payload())->assertOk();
        Http::assertSentCount(1);
        $this->postJson(route('cinemas.route'), $this->payload(['profile' => 'foot-walking']))->assertOk();
        Http::assertSentCount(2);
    }

    public function test_invalid_location_profile_and_unknown_cinema_do_not_contact_provider(): void
    {
        $this->postJson(route('cinemas.route'), $this->payload(['origin' => ['lat' => 91], 'profile' => 'https://example.org']))
            ->assertUnprocessable()->assertJsonValidationErrors(['origin.lat', 'profile']);
        $this->postJson(route('cinemas.route'), $this->payload(['origin' => ['lng' => -181]]))
            ->assertUnprocessable()->assertJsonValidationErrors('origin.lng');
        $this->postJson(route('cinemas.route'), $this->payload(['cinema_id' => 'not-a-cinema']))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_missing_key_falls_back_and_provider_errors_return_friendly_messages_without_secrets(): void
    {
        config(['services.openrouteservice.key' => null]);
        Http::fake(['valhalla1.openstreetmap.de/*' => Http::response($this->motorcycleResponse())]);
        $this->postJson(route('cinemas.route'), $this->payload())
            ->assertOk()
            ->assertJsonPath('provider', 'Valhalla');
        config(['services.openrouteservice.key' => 'routing-test-key']);
        Http::fake(['api.heigit.org/*' => Http::sequence()->push(['error' => 'private upstream error'], 403)->push([], 429)->push([], 404)->push([], 500)]);
        $this->postJson(route('cinemas.route'), $this->payload())->assertStatus(503)->assertDontSee('private upstream error');
        $this->postJson(route('cinemas.route'), $this->payload())->assertStatus(429);
        $this->postJson(route('cinemas.route'), $this->payload())->assertUnprocessable();
        $this->postJson(route('cinemas.route'), $this->payload())->assertStatus(502);
    }

    public function test_connection_failure_returns_a_friendly_error(): void
    {
        Http::fake(['api.heigit.org/*' => Http::failedConnection()]);
        $this->postJson(route('cinemas.route'), $this->payload())->assertStatus(503);
    }

    public function test_incomplete_response_does_not_cache_a_broken_route(): void
    {
        Http::fake(['api.heigit.org/*' => Http::sequence()->push(['features' => []])->push($this->routeResponse())]);
        $this->postJson(route('cinemas.route'), $this->payload())->assertStatus(502);
        $this->postJson(route('cinemas.route'), $this->payload())->assertOk();
    }

    public function test_excessive_route_requests_are_throttled(): void
    {
        Http::fake(['api.heigit.org/*' => Http::response($this->routeResponse())]);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson(route('cinemas.route'), $this->payload())->assertOk();
        }
        $this->postJson(route('cinemas.route'), $this->payload())->assertStatus(429);
        Http::assertSentCount(1);
    }

    private function motorcycleResponse(): array
    {
        return ['trip' => [
            'summary' => ['length' => 3.839, 'time' => 480.3],
            'legs' => [[
                'shape' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
                'maneuvers' => [
                    ['type' => 1, 'street_names' => ['Jalan A'], 'length' => 1.2],
                    ['type' => 10, 'street_names' => ['Jalan B'], 'length' => 2.639],
                    ['type' => 4, 'length' => 0],
                ],
            ]],
        ]];
    }

    public function test_motorcycle_uses_a_real_motorcycle_profile_without_forwarding_ors_key(): void
    {
        config(['services.openrouteservice.key' => null]);
        Http::fake(['valhalla1.openstreetmap.de/*' => Http::response($this->motorcycleResponse())]);
        $response = $this->postJson(route('cinemas.route'), $this->payload(['profile' => 'motorcycle']));
        $response->assertOk()->assertJsonPath('provider', 'Valhalla')
            ->assertJsonPath('geometry.coordinates.0', [-12.02, 3.85])
            ->assertJsonPath('geometry.coordinates.2', [-12.6453, 4.3252])
            ->assertJsonPath('distance', 3839)->assertJsonPath('duration', 480.3)
            ->assertJsonPath('steps.1.instruction', 'Belok kanan menuju Jalan B')
            ->assertJsonPath('steps.2.instruction', 'Tiba di tujuan');
        Http::assertSent(function (Request $request) {
            $data = json_decode($request['json'], true);

            return str_starts_with($request->url(), 'https://valhalla1.openstreetmap.de/route')
                && ! $request->hasHeader('Authorization')
                && $request->hasHeader('X-Client-Id', 'cinelog')
                && $data['costing'] === 'motorcycle'
                && $data['costing_options']['motorcycle']['use_tolls'] === 0
                && $data['locations'][0] === ['lat' => 3.585, 'lon' => 98.672];
        });
    }

    public function test_motorcycle_cache_and_rate_limit_protect_the_public_provider(): void
    {
        Http::fake(['valhalla1.openstreetmap.de/*' => Http::response($this->motorcycleResponse())]);
        $this->postJson(route('cinemas.route'), $this->payload(['profile' => 'motorcycle']))->assertOk();
        $this->postJson(route('cinemas.route'), $this->payload(['profile' => 'motorcycle']))->assertOk();
        $this->postJson(route('cinemas.route'), $this->payload(['profile' => 'motorcycle', 'origin' => ['lat' => 3.59]]))->assertStatus(429);
        Http::assertSentCount(1);
    }

    public function test_invalid_motorcycle_geometry_is_rejected(): void
    {
        $data = $this->motorcycleResponse();
        $data['trip']['legs'][0]['shape'] = '~';
        Http::fake(['valhalla1.openstreetmap.de/*' => Http::response($data)]);
        $this->postJson(route('cinemas.route'), $this->payload(['profile' => 'motorcycle']))->assertStatus(502);
    }
}
