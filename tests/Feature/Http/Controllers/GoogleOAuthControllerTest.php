<?php

namespace XaviCabot\FilamentAppointments\Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use XaviCabot\FilamentAppointments\Models\CalendarConnection;
use XaviCabot\FilamentAppointments\Models\CalendarSource;
use XaviCabot\FilamentAppointments\Services\GoogleCalendarServiceInterface;
use XaviCabot\FilamentAppointments\Tests\TestCase;

class GoogleOAuthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create users table for auth
        $this->app['db']->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    private function createUser(): Authenticatable
    {
        return TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_syncCalendars_without_connection_returns_422(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->postJson(
            route('filament-appointments.google.sync-calendars')
        );

        $response->assertStatus(422);
        $response->assertJson(['ok' => false]);
    }

    public function test_syncCalendars_calls_service_and_returns_calendars(): void
    {
        $user = $this->createUser();

        $conn = CalendarConnection::create([
            'user_id' => $user->getAuthIdentifier(),
            'provider' => 'google',
            'access_token' => Crypt::encryptString('token'),
            'refresh_token' => Crypt::encryptString('refresh'),
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);

        $source = CalendarSource::create([
            'connection_id' => $conn->id,
            'external_calendar_id' => 'primary',
            'name' => 'My Calendar',
            'included' => true,
            'primary' => true,
        ]);

        $mockService = Mockery::mock(GoogleCalendarServiceInterface::class);
        $mockService->shouldReceive('syncCalendars')
            ->once()
            ->with(Mockery::on(fn ($c) => $c->id === $conn->id))
            ->andReturn([$source]);

        $this->app->instance(GoogleCalendarServiceInterface::class, $mockService);

        $response = $this->actingAs($user)->postJson(
            route('filament-appointments.google.sync-calendars')
        );

        $response->assertOk();
        $response->assertJson(['ok' => true]);
        $response->assertJsonCount(1, 'calendars');
    }

    public function test_syncCalendars_api_failure_returns_500(): void
    {
        $user = $this->createUser();

        CalendarConnection::create([
            'user_id' => $user->getAuthIdentifier(),
            'provider' => 'google',
            'access_token' => Crypt::encryptString('token'),
            'refresh_token' => Crypt::encryptString('refresh'),
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);

        $mockService = Mockery::mock(GoogleCalendarServiceInterface::class);
        $mockService->shouldReceive('syncCalendars')
            ->once()
            ->andThrow(new \RuntimeException('Google API error'));

        $this->app->instance(GoogleCalendarServiceInterface::class, $mockService);

        $response = $this->actingAs($user)->postJson(
            route('filament-appointments.google.sync-calendars')
        );

        $response->assertStatus(500);
        $response->assertJson(['ok' => false]);
    }

    public function test_disconnect_deletes_connection_and_sources(): void
    {
        $user = $this->createUser();

        $conn = CalendarConnection::create([
            'user_id' => $user->getAuthIdentifier(),
            'provider' => 'google',
            'access_token' => Crypt::encryptString('token'),
            'refresh_token' => Crypt::encryptString('refresh'),
            'status' => 'connected',
        ]);

        CalendarSource::create([
            'connection_id' => $conn->id,
            'external_calendar_id' => 'primary',
            'name' => 'Calendar',
            'included' => true,
            'primary' => true,
        ]);

        $response = $this->actingAs($user)->postJson(
            route('filament-appointments.google.disconnect')
        );

        $response->assertOk();
        $this->assertDatabaseMissing('fa_connections', ['id' => $conn->id]);
        $this->assertDatabaseMissing('fa_sources', ['connection_id' => $conn->id]);
    }

    public function test_redirect_redirects_to_google(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->get(
            route('filament-appointments.google.redirect')
        );

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
    }

    public function test_callback_syncs_calendars_with_included_false(): void
    {
        $state = Crypt::encryptString('1');

        $this->createUser();

        // Mock Google Client for token exchange
        $mockClient = Mockery::mock(\Google\Client::class)->makePartial();
        $mockClient->shouldReceive('fetchAccessTokenWithAuthCode')
            ->with('test_code')
            ->once()
            ->andReturn([
                'access_token' => 'test_token',
                'refresh_token' => 'test_refresh',
                'expires_in' => 3600,
            ]);

        $this->app->instance(\Google\Client::class, $mockClient);

        // Mock sync service — creates real DB records with included = false
        $mockService = Mockery::mock(GoogleCalendarServiceInterface::class);
        $mockService->shouldReceive('syncCalendars')
            ->once()
            ->andReturnUsing(function (CalendarConnection $conn) {
                return [
                    CalendarSource::create([
                        'connection_id' => $conn->id,
                        'external_calendar_id' => 'primary@google.com',
                        'name' => 'Primary Calendar',
                        'primary' => true,
                        'included' => false,
                    ]),
                    CalendarSource::create([
                        'connection_id' => $conn->id,
                        'external_calendar_id' => 'work@google.com',
                        'name' => 'Work Calendar',
                        'primary' => false,
                        'included' => false,
                    ]),
                ];
            });

        $this->app->instance(GoogleCalendarServiceInterface::class, $mockService);

        $response = $this->get(
            route('filament-appointments.google.callback', [
                'state' => $state,
                'code' => 'test_code',
            ])
        );

        $response->assertRedirect();

        $this->assertDatabaseHas('fa_connections', [
            'user_id' => 1,
            'provider' => 'google',
            'status' => 'connected',
        ]);

        $this->assertDatabaseHas('fa_sources', [
            'external_calendar_id' => 'primary@google.com',
            'primary' => true,
            'included' => false,
        ]);

        $this->assertDatabaseHas('fa_sources', [
            'external_calendar_id' => 'work@google.com',
            'primary' => false,
            'included' => false,
        ]);
    }

    public function test_callback_redirects_when_sync_fails(): void
    {
        $state = Crypt::encryptString('1');

        $this->createUser();

        // Mock Google Client for token exchange
        $mockClient = Mockery::mock(\Google\Client::class)->makePartial();
        $mockClient->shouldReceive('fetchAccessTokenWithAuthCode')
            ->with('test_code')
            ->once()
            ->andReturn([
                'access_token' => 'test_token',
                'refresh_token' => 'test_refresh',
                'expires_in' => 3600,
            ]);

        $this->app->instance(\Google\Client::class, $mockClient);

        // Mock sync service — throws exception
        $mockService = Mockery::mock(GoogleCalendarServiceInterface::class);
        $mockService->shouldReceive('syncCalendars')
            ->once()
            ->andThrow(new \RuntimeException('Google API error'));

        $this->app->instance(GoogleCalendarServiceInterface::class, $mockService);

        $response = $this->get(
            route('filament-appointments.google.callback', [
                'state' => $state,
                'code' => 'test_code',
            ])
        );

        // Connection should be saved despite sync failure
        $this->assertDatabaseHas('fa_connections', [
            'user_id' => 1,
            'provider' => 'google',
            'status' => 'connected',
        ]);

        // No sources created
        $this->assertDatabaseCount('fa_sources', 0);

        // Should redirect without exception
        $response->assertRedirect();
    }
}

class TestUser extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password'];
}
