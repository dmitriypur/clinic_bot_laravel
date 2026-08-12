# Booking Failure Contact Details Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the patient's name and phone number to technical 1C booking failure messages in Bitrix24 so a manager can contact the patient immediately.

**Architecture:** `ApplicationController` will copy only the already validated `full_name` and `phone` fields into the existing booking failure context. `BookingAvailabilityMessageFactory` will render only those two newly approved fields alongside its existing allowlisted operational fields; arbitrary context, secrets, response bodies, and parent details remain ignored.

**Tech Stack:** PHP 8.3, Laravel 11, Laravel queues, PHPUnit 11.

## Global Constraints

- Preserve the detailed 1C error classification already implemented.
- Keep the client-facing HTTP 503 response unchanged.
- Continue deleting the failed application instead of persisting it in the admin panel.
- Add only the patient `full_name` and `phone`; do not add parent name, birth date, URL, token, response body, or raw exception text.
- Send contact details only through the existing technical booking failure notification path.

---

### Task 1: Allowlisted contact fields in Bitrix24 messages

**Files:**
- Modify: `app/Services/BookingMonitoring/BookingAvailabilityMessageFactory.php`
- Modify: `tests/Unit/BookingMonitoringNotifierTest.php`

**Interfaces:**
- Consumes: `BookingMonitoringNotifier::bookingFailure(array $safeContext): void` with optional `patient_name` and `phone` keys.
- Produces: a queued message containing `Клиент: <name>` and `Телефон: <phone>`, while all non-allowlisted keys remain absent.

- [ ] Change the existing privacy test to pass `patient_name`, `phone`, `parent_name`, `birth_date`, `url`, `token`, and `response_body`, then assert that only the patient name and phone are present.
- [ ] Run `php artisan test tests/Unit/BookingMonitoringNotifierTest.php` and verify RED because the factory currently ignores contact fields.
- [ ] Add `patient_name => Клиент` and `phone => Телефон` to the explicit label allowlist in `BookingAvailabilityMessageFactory::bookingFailure()` and update its PHPDoc.
- [ ] Re-run the unit test and verify GREEN.

### Task 2: Pass validated contacts through both technical failure paths

**Files:**
- Modify: `app/Http/Controllers/Api/ApplicationController.php`
- Modify: `tests/Feature/BookingWidgetApiContractTest.php`

**Interfaces:**
- Consumes: validated request fields `full_name` and `phone`.
- Produces: `bookingFailureContext()` keys `patient_name` and `phone` for both preflight unavailability and real 1C transport/HTTP failures.

- [ ] Strengthen both technical error feature tests to assert `Клиент: Пациент проверки` and `Телефон: 79990000009` in the queued Bitrix24 message.
- [ ] Run the two focused feature tests and verify RED because the controller does not yet add contacts to the notification context.
- [ ] Add `'patient_name' => $validated['full_name'] ?? null` and `'phone' => $validated['phone'] ?? null` to `bookingFailureContext()`.
- [ ] Re-run the focused tests and verify GREEN.
- [ ] Confirm the tests still assert the unchanged 503 client message and missing failed application.

### Task 3: Regression verification

**Files:**
- Verify all files modified by Tasks 1 and 2.

**Interfaces:**
- Consumes: the completed contact notification behavior.
- Produces: evidence that monitoring, API routing, detailed error classification, formatting, and privacy constraints remain intact.

- [ ] Run `php artisan test tests/Unit/BookingMonitoringNotifierTest.php tests/Unit/OneCBookingFailureClassifierTest.php tests/Unit/OneCTransportErrorClassifierTest.php tests/Unit/OneCApiClientErrorContextTest.php tests/Feature/BookingWidgetApiContractTest.php` and require all tests to pass.
- [ ] Run Pint only on the modified production and test files.
- [ ] Run `git diff --check` and confirm a clean working tree after commits.
- [ ] Commit production and regression changes with `feat: include patient contacts in 1C failure alerts`.
