# Filament Appointments

A complete appointment booking system for [FilamentPHP](https://filamentphp.com). Includes a visual time slot picker, weekly schedule management, manual blocks, booking with double-booking prevention, email confirmations, Google Calendar busy-time sync, and automatic Google Meet link generation.

> **v2.x** — Requires PHP 8.2+, Laravel 11.28+/12, and FilamentPHP 4 or 5.
>
> For FilamentPHP 3 support, use [v1.x](https://github.com/xavicabot/filament-appointments/tree/1.x) (`composer require xavicabot/filament-appointments:^1.0`).

---

## Installation

### 1. Install the package

```bash
composer require xavicabot/filament-appointments
```

### 2. Publish and run the migrations

```bash
php artisan vendor:publish --tag=filament-appointments-migrations
php artisan migrate
```

This creates the following tables: `fa_rules`, `fa_blocks`, `fa_bookings`, `fa_connections`, `fa_sources`, `fa_busy_cache`.

### 3. Publish the config file

```bash
php artisan vendor:publish --tag=filament-appointments-config
```

This creates `config/filament-appointments.php`.

---

## Setting Up Your Panel

### Register the Plugin

Add the plugin to your Filament `PanelProvider`:

```php
use XaviCabot\FilamentAppointments\FilamentAppointmentsPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->plugins([
                FilamentAppointmentsPlugin::make(),
            ]);
    }
}
```

This adds three resources to your sidebar under the **"Appointments"** navigation group:

- **Appointment Rules** — Define weekly availability schedules
- **Appointment Blocks** — Block specific dates/times (holidays, vacations, etc.)
- **Calendar Connections** — Manage Google Calendar integrations

### Prepare Your User Model

Add the `HasAppointments` trait to the model that **provides** appointments (e.g., advisors, doctors, professionals):

```php
use XaviCabot\FilamentAppointments\Support\HasAppointments;
use XaviCabot\FilamentAppointments\Support\HasBookings;

class User extends Authenticatable
{
    use HasAppointments, HasBookings;
}
```

- **`HasAppointments`** — Marks the model as a schedule owner. Provides `User::withAppointments()` scope (users that have active rules) and `$user->toSlotOwner()`.
- **`HasBookings`** — Allows the model to book appointments. Provides `$user->bookings()` relationship and email methods for confirmations.

> If the people who book appointments are different from those who provide them (e.g., Patient books with Doctor), add `HasAppointments` to Doctor and `HasBookings` to Patient.

### Set the Owner Model in Config

Open `config/filament-appointments.php` and set your owner model:

```php
'owner_model' => \App\Models\User::class,
'owner_label' => 'name',
```

This allows the admin resources to show a dropdown of users when creating rules and blocks, instead of raw numeric IDs.

---

## Creating Availability Schedules

Go to **Appointment Rules** in your Filament panel and create rules for each day of the week. Each rule defines:

| Field | Description | Example |
|---|---|---|
| Owner | The professional providing appointments | Dr. Smith |
| Weekday | Day of the week (Monday–Sunday) | Monday |
| Start time | When the schedule starts | 09:00 |
| End time | When the schedule ends | 13:00 |
| Interval | Minutes between each slot | 30 |
| Active | Enable/disable the rule | Yes |

**Example:** A rule for Monday, 09:00–13:00 with 30-min intervals generates: 09:00, 09:30, 10:00, 10:30, 11:00, 11:30, 12:00, 12:30.

You can create multiple rules for the same day (e.g., morning 09:00–13:00 + afternoon 15:00–18:00).

---

## Blocking Dates

Go to **Appointment Blocks** to manually mark dates or time ranges as unavailable:

- **All-day block:** Set the date only (leave start/end time empty) — blocks the entire day
- **Partial block:** Set date + start/end time — blocks only that time range
- **Reason:** Optional note (e.g., "Public holiday", "Vacation")

---

## Using the Appointment Picker

The `AppointmentPicker` is a Filament form field that shows a date input and a dropdown grid of available time slots.

### Basic Example

```php
use XaviCabot\FilamentAppointments\Forms\Components\AppointmentPicker;
use XaviCabot\FilamentAppointments\Support\SlotOwner;

AppointmentPicker::make('time_slot')
    ->label('Pick a time')
    ->owner(fn () => SlotOwner::forUser(auth()->user()))
    ->minDate(now())
    ->maxDate(now()->addDays(30))
    ->required()
```

### With an Advisor Selector

When users need to choose who they're booking with:

```php
use Filament\Forms\Get;
use XaviCabot\FilamentAppointments\Forms\Components\AppointmentPicker;
use XaviCabot\FilamentAppointments\Support\SlotOwner;

// Advisor dropdown
Forms\Components\Select::make('advisor_id')
    ->label('Advisor')
    ->options(User::withAppointments()->pluck('name', 'id'))
    ->required()
    ->live(),

// Time slot picker — reacts to selected advisor
AppointmentPicker::make('time_slot')
    ->label('Pick a time')
    ->owner(fn (Get $get) => $get('advisor_id')
        ? new SlotOwner('user', (int) $get('advisor_id'))
        : null
    )
    ->minDate(now())
    ->maxDate(now()->addDays(30))
    ->required()
    ->visible(fn (Get $get) => filled($get('advisor_id'))),
```

### Field Value

The selected value is stored as a string in the format `"YYYY-MM-DD HH:MM"` (e.g., `"2026-02-16 09:30"`).

To parse it:

```php
$parts = explode(' ', $data['time_slot'], 2);
$date = $parts[0];  // "2026-02-16"
$time = $parts[1];  // "09:30"
```

### Available Methods

| Method | Description |
|---|---|
| `owner(callable)` | Closure returning a `SlotOwner`. Determines whose schedule is shown. |
| `minDate(string\|DateTime\|Closure)` | Earliest selectable date. |
| `maxDate(string\|DateTime\|Closure)` | Latest selectable date. |

---

## Creating Bookings

Use `AppointmentService` to create bookings with double-booking protection:

```php
use XaviCabot\FilamentAppointments\Services\AppointmentService;
use XaviCabot\FilamentAppointments\Support\SlotOwner;

$service = app(AppointmentService::class);

$booking = $service->createBooking(
    owner: new SlotOwner('user', $advisorId),
    date: '2026-02-20',
    startTime: '10:00',
    client: auth()->user(),
);
```

This automatically:

1. Determines the slot duration from the matching rule
2. Prevents double-booking (database-level lock)
3. Creates a Google Meet link if the owner has a connected Google account
4. Sends a confirmation email to the client
5. Returns an `AppointmentBooking` model

### Full Page Example

Here's a complete Filament page that lets users book appointments:

```php
use XaviCabot\FilamentAppointments\Forms\Components\AppointmentPicker;
use XaviCabot\FilamentAppointments\Services\AppointmentService;
use XaviCabot\FilamentAppointments\Support\SlotOwner;

// In your form method:
$schema = [
    Forms\Components\Select::make('advisor_id')
        ->label('Advisor')
        ->options(User::withAppointments()->pluck('name', 'id'))
        ->required()
        ->live(),

    AppointmentPicker::make('time_slot')
        ->label('Pick a time')
        ->owner(fn (Get $get) => $get('advisor_id')
            ? new SlotOwner('user', (int) $get('advisor_id'))
            : null
        )
        ->minDate(now())
        ->maxDate(now()->addDays(30))
        ->required()
        ->visible(fn (Get $get) => filled($get('advisor_id'))),

    Forms\Components\Textarea::make('notes')
        ->label('Notes (optional)')
        ->rows(2),
];

// In your submit method:
public function submit(): void
{
    $data = $this->form->getState();

    $parts = explode(' ', $data['time_slot'], 2);
    $date = $parts[0];
    $time = $parts[1] ?? '00:00';

    $owner = new SlotOwner('user', (int) $data['advisor_id']);

    $booking = app(AppointmentService::class)
        ->createBooking($owner, $date, $time, auth()->user());

    Notification::make()
        ->title('Appointment booked!')
        ->success()
        ->send();
}
```

---

## Email Confirmations

By default, bookings are created with status `confirmed`. You can require email confirmation first.

### Enable Confirmation Flow

In `config/filament-appointments.php`:

```php
'bookings' => [
    'require_confirmation' => true,
    'confirmation_ttl_hours' => 24,
],
```

### How It Works

1. Booking is created with status **`pending`**
2. Client receives an email with a **signed URL** button
3. Client clicks the button — booking status changes to **`confirmed`**
4. If the link expires (after `confirmation_ttl_hours`), the booking can be auto-cancelled

The email includes the appointment date/time, a confirmation button, the expiration notice, and a Google Meet link (if available).

### Auto-Cancel Expired Bookings

Schedule the artisan command to automatically cancel unconfirmed bookings:

```php
// Laravel 11+ (routes/console.php)
Schedule::command('fa:expire-appointments')->hourly();

// Laravel 10 (app/Console/Kernel.php)
$schedule->command('fa:expire-appointments')->hourly();
```

Or run manually:

```bash
php artisan fa:expire-appointments
```

---

## Google Calendar Integration

The package can sync with Google Calendar to:

- **Mark slots as unavailable** when the owner has events in Google Calendar (freeBusy API)
- **Create Google Calendar events with Meet links** when bookings are made

### Step 1: Create Google OAuth Credentials

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a project (or use an existing one)
3. Enable the **Google Calendar API**
4. Go to **Credentials** > **Create Credentials** > **OAuth 2.0 Client ID**
5. Application type: **Web application**
6. Add an authorized redirect URI:
   ```
   https://your-domain.com/filament-appointments/google/callback
   ```

### Step 2: Add Environment Variables

Add to your `.env`:

```env
GOOGLE_CLIENT_ID=your-google-client-id
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_REDIRECT_URI=https://your-domain.com/filament-appointments/google/callback
```

> The redirect URI must match **exactly** what you configured in Google Cloud Console.

### Step 3: Connect an Account from Filament

1. Go to **Calendar Connections** in your Filament panel
2. Click **"Connect Google"**
3. Select the user to connect
4. Complete the Google OAuth consent screen
5. Once connected, click **"Sync"** to fetch the list of Google Calendars
6. Toggle **"Included"** for each calendar you want to check for busy times

### Two-Way Sync

The integration works in **both directions**:

#### Google Calendar → App (busy-time detection)

When slots are loaded for a date, the package automatically checks the owner's Google Calendar for existing events:

1. Fetches the owner's connected calendars (only those marked as "Included")
2. Calls the Google Calendar **freeBusy** API for that date
3. Any slot that overlaps with a Google Calendar event is marked as unavailable
4. Results are cached to avoid excessive API calls

So if the owner has a meeting in Google Calendar from 10:00 to 11:00, the slots at 10:00 and 10:30 will automatically appear as unavailable in the appointment picker.

The cache TTL is configurable:

```php
// config/filament-appointments.php
'google' => [
    'cache_ttl_seconds' => 120, // Cache busy windows for 2 minutes
],
```

> Sync is near real-time: there is a maximum delay equal to the cache TTL (2 minutes by default). After that, any change in Google Calendar is reflected in the available slots.

#### App → Google Calendar (event creation + Meet link)

When a booking is created and the owner has a connected Google account:

1. A **Google Calendar event** is automatically created on the owner's primary calendar — this blocks that time slot in Google Calendar too
2. A **Google Meet conference link** is generated for the event
3. The Meet URL is stored in `$booking->metadata['meet_link']`
4. The Meet link is included in the confirmation email sent to the client

This means the owner's Google Calendar stays in sync: if someone books an appointment through your app, it appears as a calendar event with a Meet link, and that time is blocked for future Google Calendar scheduling.

If no Google connection exists, the booking proceeds normally without a calendar event or Meet link.

---

## Configuration Reference

Full `config/filament-appointments.php`:

```php
return [
    // URL prefix for all package routes (slots endpoint, Google OAuth, etc.)
    'route_prefix' => 'filament-appointments',

    // Auto-register admin resources (Rules, Blocks, Calendar Connections)
    // Set to false if you want to register them manually or not at all
    'register_resources' => true,

    // The Eloquent model that owns schedules
    // Used in admin dropdowns to list available owners
    'owner_model' => \App\Models\User::class,

    // Which attribute to display in owner dropdowns
    'owner_label' => 'name',

    // Timezone for slot generation
    'timezone' => 'Europe/Madrid',

    // Custom slot resolver class (advanced)
    'resolver' => \XaviCabot\FilamentAppointments\Support\SlotResolver::class,

    // Booking behavior
    'bookings' => [
        // If true, bookings start as "pending" and require email confirmation
        'require_confirmation' => false,

        // Hours before unconfirmed bookings are auto-cancelled
        'confirmation_ttl_hours' => 24,
    ],

    // Google Calendar settings
    'google' => [
        // How long to cache busy windows per owner/date (seconds)
        'cache_ttl_seconds' => 120,
    ],
];
```

---

## Customizing Views

Publish the views to customize the look of the appointment picker, emails, and confirmation page:

```bash
php artisan vendor:publish --tag=filament-appointments-views
```

This copies the views to `resources/views/vendor/filament-appointments/`:

| View | Description |
|---|---|
| `forms/components/appointment-picker.blade.php` | The slot picker (Alpine.js + Tailwind) |
| `emails/appointment-confirmed.blade.php` | Email sent when a booking is confirmed |
| `emails/appointment-pending.blade.php` | Email sent when a booking requires confirmation |
| `bookings/confirmed.blade.php` | Page shown after clicking the confirmation link |

---

## Translations

The package includes translations in 5 languages: **English**, **Spanish**, **French**, **German**, and **Italian**.

The language is determined by your Laravel app locale (`config/app.php` > `locale`).

To override translations, publish them:

```bash
php artisan vendor:publish --tag=filament-appointments-translations
```

Or add your own files in:

```
resources/lang/vendor/filament-appointments/{locale}/messages.php
```

---

## Disabling Admin Resources

If you don't want the package to register its admin resources automatically (e.g., you want to manage rules via your own UI):

```php
// config/filament-appointments.php
'register_resources' => false,
```

---

## License

MIT
