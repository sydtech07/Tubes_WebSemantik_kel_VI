<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Notifications\BookingPaid;
use App\Services\MidtransPayment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentNotificationTest extends TestCase
{
    private Booking $booking;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'services.midtrans.server_key' => 'test-server', 'services.midtrans.signature_key' => 'test-signature',
            'services.midtrans.is_production' => false, 'services.midtrans.notification_url' => null]);
        DB::purge('sqlite');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('role')->default('user');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_11_19_000001_create_bookings_tables.php'))->up();
        (require database_path('migrations/2026_11_21_020659_add_midtrans_fields_to_bookings_table.php'))->up();
        (require database_path('migrations/2026_11_24_210000_create_notifications_table.php'))->up();
        Notification::fake();
        $this->user = User::create(['name' => 'Penonton', 'email' => 'viewer@example.test', 'password' => 'password']);
        $this->booking = Booking::create(['user_id' => $this->user->id, 'screening_iri' => 'http://example.test/screening/1',
            'movie_slug' => 'film-contoh', 'cinema_id' => 'centre-point-xxi', 'show_start' => now()->addDay(),
            'seat_count' => 1, 'total_amount' => 45000, 'status' => 'pending', 'midtrans_order_id' => 'CINELOG-TEST-1']);
        Http::preventStrayRequests();
    }

    private function payload(string $status = 'settlement', string $amount = '45000.00'): array
    {
        $data = ['order_id' => $this->booking->midtrans_order_id, 'status_code' => '200',
            'gross_amount' => $amount, 'currency' => 'IDR', 'transaction_status' => $status, 'fraud_status' => 'accept'];
        $data['signature_key'] = hash('sha512', $data['order_id'].$data['status_code'].$amount.'test-signature');

        return $data;
    }

    public function test_stale_booking_instances_reuse_one_token_and_order(): void
    {
        $this->booking->update(['midtrans_order_id' => null]);
        $first = $this->booking->fresh();
        $second = $this->booking->fresh();
        Http::fake(['https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'single-token'])]);
        $service = app(MidtransPayment::class);
        $this->assertSame('single-token', $service->snapToken($first));
        $this->assertSame('single-token', $service->snapToken($second));
        $this->assertSame('CINELOG-'.$first->id, $first->fresh()->midtrans_order_id);
        Http::assertSentCount(1);
    }

    public function test_failed_request_preserves_order_id_for_retry(): void
    {
        $this->booking->update(['midtrans_order_id' => null]);
        Http::fakeSequence()->pushResponse(Http::failedConnection())->push(['token' => 'retry-token']);
        try {
            app(MidtransPayment::class)->snapToken($this->booking);
            $this->fail('Expected connection failure');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $orderId = $this->booking->fresh()->midtrans_order_id;
        $this->assertNotNull($orderId);
        $this->travel(2)->seconds();
        $this->assertSame('retry-token', app(MidtransPayment::class)->snapToken($this->booking));
        $this->assertSame($orderId, $this->booking->fresh()->midtrans_order_id);
        Http::assertSent(fn ($request) => $request['transaction_details']['order_id'] === $orderId);
    }

    public function test_stale_pending_booking_cannot_start_payment_after_settlement(): void
    {
        $stale = $this->booking->fresh();
        $this->booking->update(['status' => 'paid']);
        Http::fake();
        try {
            app(MidtransPayment::class)->snapToken($stale);
            $this->fail('Expected rejection');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_valid_notification_marks_paid_and_retries_preserve_paid_at(): void
    {
        $url = '/payments/midtrans/notification';
        $this->postJson($url, $this->payload())->assertOk();
        $paidAt = $this->booking->fresh()->paid_at->toIso8601String();
        $this->travel(5)->minutes();
        $this->postJson($url, $this->payload())->assertOk();
        $this->postJson($url, $this->payload('expire'))->assertOk();
        $this->assertSame('paid', $this->booking->fresh()->status);
        $this->assertSame($paidAt, $this->booking->fresh()->paid_at->toIso8601String());
        Notification::assertSentToTimes($this->user, BookingPaid::class, 1);
    }

    public function test_invalid_signature_or_amount_cannot_mark_booking_paid(): void
    {
        $data = $this->payload();
        $data['signature_key'] = str_repeat('a', 128);
        $this->postJson('/payments/midtrans/notification', $data)->assertForbidden();
        $this->postJson('/payments/midtrans/notification', $this->payload('settlement', '1.00'))->assertUnprocessable();
        $this->assertSame('pending', $this->booking->fresh()->status);
    }

    public function test_missing_secret_rejects_notifications(): void
    {
        config(['services.midtrans.signature_key' => '', 'services.midtrans.server_key' => '']);
        $this->postJson('/payments/midtrans/notification', $this->payload())->assertStatus(503);
        $this->assertSame('pending', $this->booking->fresh()->status);
    }

    public function test_localhost_sync_uses_authenticated_midtrans_status(): void
    {
        Http::fake(['https://api.sandbox.midtrans.com/v2/*/status' => Http::response($this->payload())]);
        $this->actingAs($this->user)->postJson(route('bookings.payment-status', $this->booking))
            ->assertOk()->assertJsonPath('status', 'paid');
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-server:')));
        $this->assertSame('paid', $this->booking->fresh()->status);
    }

    public function test_another_user_cannot_check_payment(): void
    {
        Http::fake();
        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'password']);
        $this->actingAs($other)->postJson(route('bookings.payment-status', $this->booking))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_browser_cannot_claim_paid_and_pending_remains_pending(): void
    {
        Http::fake(['https://api.sandbox.midtrans.com/v2/*/status' => Http::response($this->payload('pending'))]);
        $this->actingAs($this->user)->postJson(route('bookings.payment-status', $this->booking), ['status' => 'paid'])
            ->assertOk()->assertJsonPath('status', 'pending');
        $this->assertNull($this->booking->fresh()->paid_at);
    }

    public function test_wrong_order_and_unavailable_api_do_not_update_booking(): void
    {
        $data = $this->payload();
        $data['order_id'] = 'ANOTHER-ORDER';
        Http::fakeSequence()->push($data)->push([], 401)->push([], 403)->pushResponse(Http::failedConnection());
        $this->actingAs($this->user);
        $url = route('bookings.payment-status', $this->booking);
        $this->postJson($url)->assertStatus(502);
        $this->postJson($url)->assertUnprocessable();
        $this->postJson($url)->assertUnprocessable()->assertSee('Core API: Read');
        $this->postJson($url)->assertUnprocessable();
        $this->assertSame('pending', $this->booking->fresh()->status);
    }

    public function test_existing_snap_token_is_reused_and_override_is_optional(): void
    {
        config(['services.midtrans.notification_url' => 'https://cinelog.example.test/payments/midtrans/notification']);
        $this->booking->update(['midtrans_snap_token' => 'saved-token']);
        $payment = app(MidtransPayment::class);
        $this->assertSame('saved-token', $payment->snapToken($this->booking));
        $this->assertSame(config('services.midtrans.notification_url'), \Midtrans\Config::$overrideNotifUrl);
        config(['services.midtrans.notification_url' => null]);
        new MidtransPayment;
        $this->assertNull(\Midtrans\Config::$overrideNotifUrl);
    }

    public function test_booking_page_has_local_status_check(): void
    {
        $this->actingAs($this->user)->get(route('bookings.show', $this->booking))
            ->assertOk()->assertSee('Cek status pembayaran')
            ->assertDontSee('Mode Sandbox')
            ->assertDontSee('test-server');
    }

    public function test_paid_booking_has_owner_only_receipt(): void
    {
        Schema::create('movies', function (Blueprint $table) {
            $table->string('slug')->primary();
            $table->string('title');
        });
        DB::table('movies')->insert(['slug' => 'film-contoh', 'title' => 'Film Contoh']);
        $this->booking->update(['status' => 'paid', 'paid_at' => now()]);
        $this->booking->seats()->create(['screening_iri' => $this->booking->screening_iri, 'seat_code' => 'A01']);

        $this->actingAs($this->user)->get(route('bookings.receipt', $this->booking))
            ->assertOk()
            ->assertSee('Receipt pembayaran')
            ->assertSee('Film Contoh')
            ->assertSee('A01')
            ->assertSee('45.000');

        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'password']);
        $this->actingAs($other)->get(route('bookings.receipt', $this->booking))->assertForbidden();
    }

    public function test_notifications_page_marks_unread_items_as_read(): void
    {
        $notification = $this->user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => BookingPaid::class,
            'data' => [
                'message' => 'Pembayaran booking #'.$this->booking->id.' berhasil.',
                'url' => route('bookings.receipt', $this->booking),
            ],
        ]);

        $this->actingAs($this->user)->get(route('notifications'))
            ->assertOk()
            ->assertSee('Notifikasi')
            ->assertSee('Baru')
            ->assertSee('Pembayaran booking #'.$this->booking->id.' berhasil.')
            ->assertSee('Lihat receipt');

        $this->assertNotNull($notification->fresh()->read_at);
    }
}
