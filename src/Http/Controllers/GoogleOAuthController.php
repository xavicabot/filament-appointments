<?php

namespace XaviCabot\FilamentAppointments\Http\Controllers;

use Google\Client as GoogleClient;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use XaviCabot\FilamentAppointments\Filament\Resources\CalendarConnectionResource;
use XaviCabot\FilamentAppointments\Models\CalendarConnection;
use XaviCabot\FilamentAppointments\Models\CalendarSource;
use XaviCabot\FilamentAppointments\Services\GoogleCalendarServiceInterface;

class GoogleOAuthController extends Controller
{
    public function redirect(Request $request)
    {
        // user_id can come from query param (Filament action) or from session
        $userId = $request->query('user_id')
            ? (int) $request->query('user_id')
            : $this->resolveUserId($request);

        if (! $userId) {
            abort(401);
        }

        $client = $this->client();
        $client->setState(Crypt::encryptString((string) $userId));
        $authUrl = $client->createAuthUrl();

        return redirect()->away($authUrl);
    }

    public function callback(Request $request)
    {
        $state = (string) $request->query('state', '');
        if (! $state) {
            abort(400, 'Missing state parameter.');
        }

        try {
            $userId = (int) Crypt::decryptString($state);
        } catch (\Throwable $e) {
            abort(403, 'Invalid state parameter.');
        }

        $code = (string) $request->query('code', '');
        if (! $code) {
            return redirect('/')->with('error', __('filament-appointments::messages.google.missing_code'));
        }

        $client = $this->client();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        $conn = CalendarConnection::query()->updateOrCreate(
            ['user_id' => $userId, 'provider' => 'google'],
            [
                'access_token' => Crypt::encryptString((string) ($token['access_token'] ?? '')),
                'refresh_token' => Crypt::encryptString((string) ($token['refresh_token'] ?? '')),
                'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
                'scopes' => is_array($client->getScopes()) ? $client->getScopes() : explode(' ', $client->getScopes()),
                'status' => 'connected',
            ],
        );

        try {
            $service = app(GoogleCalendarServiceInterface::class);
            $service->syncCalendars($conn);
        } catch (\Throwable $e) {
            Log::error('Calendar sync after OAuth failed', [
                'connection_id' => $conn->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            return redirect(CalendarConnectionResource::getUrl('view', ['record' => $conn]));
        } catch (\Throwable $e) {
            return redirect('/')->with('success', __('filament-appointments::messages.google.connected'));
        }
    }

    public function disconnect(Request $request)
    {
        $userId = $this->resolveUserId($request);

        if (! $userId) {
            abort(401);
        }

        CalendarSource::query()
            ->whereIn('connection_id', CalendarConnection::query()->where('user_id', $userId)->pluck('id'))
            ->delete();

        CalendarConnection::query()
            ->where('user_id', $userId)
            ->where('provider', 'google')
            ->delete();

        return response()->json(['ok' => true]);
    }

    public function syncCalendars(Request $request)
    {
        $userId = $this->resolveUserId($request);

        if (! $userId) {
            abort(401);
        }

        $conn = CalendarConnection::query()
            ->where('user_id', $userId)
            ->where('provider', 'google')
            ->first();

        if (! $conn) {
            return response()->json(['ok' => false, 'message' => __('filament-appointments::messages.google.not_connected')], 422);
        }

        try {
            $service = app(GoogleCalendarServiceInterface::class);
            $calendars = $service->syncCalendars($conn);

            return response()->json([
                'ok' => true,
                'calendars' => collect($calendars)->map(fn ($cal) => [
                    'id' => $cal->id,
                    'external_calendar_id' => $cal->external_calendar_id,
                    'name' => $cal->name,
                    'included' => $cal->included,
                    'primary' => $cal->primary,
                ])->values(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => __('filament-appointments::messages.google.sync_failed')], 500);
        }
    }

    /**
     * Resolve the authenticated user ID, trying multiple auth guards.
     */
    private function resolveUserId(Request $request): ?int
    {
        // Try request user (works with standard Laravel auth)
        $user = $request->user();

        if ($user) {
            return (int) $user->getAuthIdentifier();
        }

        // Try common guards explicitly (covers Filament setups)
        foreach (['web', 'filament'] as $guard) {
            try {
                $user = auth()->guard($guard)->user();
                if ($user) {
                    return (int) $user->getAuthIdentifier();
                }
            } catch (\Throwable $e) {
                // Guard doesn't exist, skip
            }
        }

        return null;
    }

    protected function client(): GoogleClient
    {
        $client = app(GoogleClient::class);
        $client->setClientId((string) env('GOOGLE_CLIENT_ID'));
        $client->setClientSecret((string) env('GOOGLE_CLIENT_SECRET'));
        $client->setRedirectUri((string) env('GOOGLE_REDIRECT_URI', route('filament-appointments.google.callback')));

        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $client->setScopes([
            'https://www.googleapis.com/auth/calendar.readonly',
            'https://www.googleapis.com/auth/calendar.events',
        ]);

        return $client;
    }
}
