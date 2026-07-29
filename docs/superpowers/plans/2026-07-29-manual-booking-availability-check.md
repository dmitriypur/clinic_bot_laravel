# Manual Booking Availability Check Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a super-admin-only dashboard button that immediately checks 1C booking availability, shows a clear Filament notification, and queues a clearly labeled manual result for Bitrix24.

**Architecture:** The dashboard action will reuse `BookingAvailabilityChecker`, the existing cache lock, and `BookingMonitoringNotifier`. The notifier/message factory will gain a separate `manualCheck()` path so Bitrix24 can distinguish manual checks from scheduled checks without duplicating delivery code.

**Tech Stack:** Laravel 11, PHP 8.3, Filament 3, Livewire 3, Laravel HTTP client, Laravel cache locks, Laravel queues, PHPUnit.

## Global Constraints

- Only a super-administrator may see or execute the action.
- When `BOOKING_MONITORING_ENABLED=false`, do not call 1C and do not queue Bitrix24 messages.
- Use the existing `BOOKING_MONITORING_URL`, HEAD request, timeouts, queue, and `booking-monitoring:availability-check` lock.
- Do not change the three scheduled checks, the client booking widget, its API, the database schema, or Supervisor/cron deployment setup.
- Manual Bitrix24 messages must contain technical check data only and be labeled `Ручная проверка 1С`.

---

### Task 1: Manual Bitrix24 check message

**Files:**
- Modify: `app/Services/BookingMonitoring/BookingAvailabilityMessageFactory.php`
- Modify: `app/Services/BookingMonitoring/BookingMonitoringNotifier.php`
- Test: `tests/Unit/BookingMonitoringNotifierTest.php`

**Interfaces:**
- Consumes: `BookingAvailabilityCheckResult` and the existing `SendBookingMonitoringMessage` job.
- Produces: `BookingAvailabilityMessageFactory::manualCheck(BookingAvailabilityCheckResult): string` and `BookingMonitoringNotifier::manualCheck(BookingAvailabilityCheckResult): void`.

- [ ] **Step 1: Write the failing notifier test**

Add a test that proves a manual result is queued and clearly distinguished from a scheduled result:

```php
public function test_it_queues_a_clearly_labeled_manual_check_result(): void
{
    Queue::fake();

    app(BookingMonitoringNotifier::class)->manualCheck(
        new BookingAvailabilityCheckResult(true, 200, 37)
    );

    Queue::assertPushed(SendBookingMonitoringMessage::class, function ($job): bool {
        return str_contains($job->message(), 'Ручная проверка 1С')
            && str_contains($job->message(), 'ДОСТУПНА')
            && str_contains($job->message(), 'HTTP 200')
            && str_contains($job->message(), '37 мс');
    });
}
```

Extend the disabled-monitoring test to invoke `manualCheck()` and retain `Queue::assertNothingPushed()`.

- [ ] **Step 2: Run the test and confirm RED**

Run:

```bash
php artisan test --filter='test_it_queues_a_clearly_labeled_manual_check_result|test_disabled_monitoring_neither_queues_nor_delivers_messages' tests/Unit/BookingMonitoringNotifierTest.php
```

Expected: failure because `manualCheck()` does not exist.

- [ ] **Step 3: Add the manual message path**

Refactor the common availability text into a private method and keep the public labels explicit:

```php
public function scheduledCheck(BookingAvailabilityCheckResult $result): string
{
    return $this->availabilityCheck($result, 'Плановая проверка 1С');
}

public function manualCheck(BookingAvailabilityCheckResult $result): string
{
    return $this->availabilityCheck($result, 'Ручная проверка 1С');
}

private function availabilityCheck(BookingAvailabilityCheckResult $result, string $checkLabel): string
{
    // Preserve the existing status, time, URL, HTTP response, duration, and safe error lines.
}
```

Add the notifier method with the same enabled guard used by the scheduled path:

```php
public function manualCheck(BookingAvailabilityCheckResult $result): void
{
    if (! config('booking-monitoring.enabled')) {
        return;
    }

    $this->dispatch($this->messages->manualCheck($result));
}
```

- [ ] **Step 4: Run the notifier tests and confirm GREEN**

Run:

```bash
php artisan test tests/Unit/BookingMonitoringNotifierTest.php
```

Expected: all notifier tests pass and scheduled message assertions remain unchanged.

- [ ] **Step 5: Commit the notifier change**

```bash
git add app/Services/BookingMonitoring/BookingAvailabilityMessageFactory.php app/Services/BookingMonitoring/BookingMonitoringNotifier.php tests/Unit/BookingMonitoringNotifierTest.php
git commit -m "feat: add manual booking check notification"
```

---

### Task 2: Super-admin dashboard action

**Files:**
- Modify: `app/Filament/Pages/Dashboard.php`
- Create: `tests/Unit/DashboardManualBookingCheckTest.php`

**Interfaces:**
- Consumes: `BookingAvailabilityChecker::check(): BookingAvailabilityCheckResult` and `BookingMonitoringNotifier::manualCheck(BookingAvailabilityCheckResult): void` from Task 1.
- Produces: Filament header action `checkBookingAvailability` and public page method `checkBookingAvailability(BookingAvailabilityChecker, BookingMonitoringNotifier): void`.

- [ ] **Step 1: Write failing authorization and disabled-state tests**

Create a Laravel unit test that authenticates a partial `User` mock whose `isSuperAdmin()` result is controlled by the test. Verify:

```php
public function test_manual_action_is_visible_only_to_super_admin(): void
{
    $this->authenticateUser(isSuperAdmin: true);
    $this->assertTrue($this->dashboardAction()->isVisible());

    $this->authenticateUser(isSuperAdmin: false);
    $this->assertFalse($this->dashboardAction()->isVisible());
}

public function test_disabled_monitoring_does_not_contact_one_c_or_bitrix(): void
{
    config(['booking-monitoring.enabled' => false]);
    Http::fake();
    Queue::fake();

    $this->dashboard()->checkBookingAvailability(
        app(BookingAvailabilityChecker::class),
        app(BookingMonitoringNotifier::class),
    );

    Http::assertNothingSent();
    Queue::assertNothingPushed();
    Notification::assertNotified('Мониторинг отключён');
}
```

The test helper must expose `getHeaderActions()` through a test-only Dashboard subclass and set a partial `User` mock through `Auth::setUser()`. Add a separate test asserting a non-super-admin direct invocation throws HTTP 403 and sends no HTTP request.

- [ ] **Step 2: Run the first dashboard tests and confirm RED**

Run:

```bash
php artisan test tests/Unit/DashboardManualBookingCheckTest.php
```

Expected: failure because the action and page method do not exist.

- [ ] **Step 3: Add the Filament action and guards**

Add `getHeaderActions()`:

```php
protected function getHeaderActions(): array
{
    return [
        Action::make('checkBookingAvailability')
            ->label('Проверить доступность 1С')
            ->icon('heroicon-o-signal')
            ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false)
            ->action(function (
                BookingAvailabilityChecker $checker,
                BookingMonitoringNotifier $notifier,
            ): void {
                $this->checkBookingAvailability($checker, $notifier);
            }),
    ];
}
```

Start the public method with both server-side authorization and the disabled guard:

```php
abort_unless(auth()->user()?->isSuperAdmin(), 403);

if (! config('booking-monitoring.enabled')) {
    Notification::make()
        ->title('Мониторинг отключён')
        ->warning()
        ->send();

    return;
}
```

- [ ] **Step 4: Write failing success, failure, and shared-lock tests**

Use `Http::fake()` and `Queue::fake()` to verify:

```php
// HTTP 200: HEAD sent, manual queue message contains "Ручная проверка 1С",
// and Notification::assertNotified('Запись в 1С доступна').

// HTTP 503: HEAD sent, manual queue message contains "ЗАПИСЬ НЕДОСТУПНА",
// and Notification::assertNotified('Запись в 1С недоступна').

// Pre-acquired Cache::lock('booking-monitoring:availability-check', 60):
// no HTTP request, no queued message, and
// Notification::assertNotified('Проверка уже выполняется').
```

- [ ] **Step 5: Run the new tests and confirm RED**

Run:

```bash
php artisan test tests/Unit/DashboardManualBookingCheckTest.php
```

Expected: the newly added result/lock assertions fail because the action does not yet execute the check.

- [ ] **Step 6: Implement the locked manual check and UI result**

Use the same lock key and duration as the scheduler command:

```php
$lock = Cache::lock(
    'booking-monitoring:availability-check',
    (int) config('booking-monitoring.lock_seconds', 60),
);

if (! $lock->get()) {
    Notification::make()
        ->title('Проверка уже выполняется')
        ->warning()
        ->send();

    return;
}

try {
    $result = $checker->check();
    $notifier->manualCheck($result);

    $notification = Notification::make()
        ->title($result->available ? 'Запись в 1С доступна' : 'Запись в 1С недоступна')
        ->body($result->available
            ? "HTTP {$result->statusCode}, время ответа: {$result->durationMs} мс."
            : ($result->error ?? 'Сервер 1С не ответил.'));

    $result->available ? $notification->success() : $notification->danger();
    $notification->send();
} finally {
    $lock->release();
}
```

- [ ] **Step 7: Run dashboard and monitoring regression tests**

Run:

```bash
php artisan test tests/Unit/DashboardManualBookingCheckTest.php tests/Unit/BookingMonitoringNotifierTest.php tests/Unit/BookingAvailabilityCheckerTest.php tests/Feature/BookingAvailabilityCommandTest.php
```

Expected: all tests pass; the scheduled command still registers and queues scheduled messages.

- [ ] **Step 8: Commit the dashboard action**

```bash
git add app/Filament/Pages/Dashboard.php tests/Unit/DashboardManualBookingCheckTest.php
git commit -m "feat: add manual 1C availability check action"
```

---

### Task 3: Final regression and deployment-safety verification

**Files:**
- Verify only; no production file changes expected.

**Interfaces:**
- Consumes: the completed notifier and dashboard action.
- Produces: verification evidence that the feature is safe to deploy.

- [ ] **Step 1: Format changed PHP files**

Run:

```bash
./vendor/bin/pint app/Filament/Pages/Dashboard.php app/Services/BookingMonitoring/BookingAvailabilityMessageFactory.php app/Services/BookingMonitoring/BookingMonitoringNotifier.php tests/Unit/DashboardManualBookingCheckTest.php tests/Unit/BookingMonitoringNotifierTest.php
```

- [ ] **Step 2: Run the complete monitoring/API regression set**

Run:

```bash
php artisan test tests/Unit/DashboardManualBookingCheckTest.php tests/Unit/BookingAvailabilityCheckerTest.php tests/Unit/BookingMonitoringNotifierTest.php tests/Unit/ProductionDeploymentConfigurationTest.php tests/Feature/BookingAvailabilityCommandTest.php tests/Feature/BookingWidgetApiContractTest.php tests/Feature/VerifyBookingMonitoringConfigCommandTest.php
```

Expected: all selected tests pass. If the unrelated historical SQLite migration failures remain in the full suite, report them separately without changing unrelated migrations.

- [ ] **Step 3: Verify syntax and patch cleanliness**

Run:

```bash
php -l app/Filament/Pages/Dashboard.php
php -l app/Services/BookingMonitoring/BookingAvailabilityMessageFactory.php
php -l app/Services/BookingMonitoring/BookingMonitoringNotifier.php
git diff --check
git status --short --branch
```

Expected: PHP syntax is valid, `git diff --check` is clean, and only planned files are changed or committed.
