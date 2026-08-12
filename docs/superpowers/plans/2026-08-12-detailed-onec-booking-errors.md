# Detailed 1C Booking Errors Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the generic Bitrix24 booking failure reason with a safe, specific explanation of the HTTP, timeout, DNS, TLS, refused-connection, reset-connection, malformed-response, or unknown technical failure.

**Architecture:** `OneCApiClient` will convert transport exceptions into a small safe diagnostic context without exposing URLs, payloads, bodies, or credentials. `OneCBookingFailureClassifier` will translate that context and HTTP status codes into human-readable Russian messages, while the existing controller, queue, client-facing HTTP 503 response, failed-application cleanup, and monitoring schedule remain unchanged.

**Tech Stack:** PHP 8.3, Laravel 11 HTTP client, Guzzle/cURL transport exceptions, PHPUnit 11, Laravel queues.

## Global Constraints

- Do not persist failed applications or retry booking automatically.
- Keep the client-facing `booking_service_unavailable` HTTP 503 response unchanged.
- Keep scheduled/manual HEAD monitoring and the external booking widget unchanged.
- Never put endpoint URLs, authorization data, request/response bodies, patient name, phone, birth date, or raw exception text into Bitrix24.
- Preserve the existing distinction between technical failures (503 + alert) and domain rejections (422 without alert).

---

### Task 1: Safe transport error diagnostics

**Files:**
- Create: `app/Services/OneC/OneCTransportErrorClassifier.php`
- Modify: `app/Services/OneC/OneCApiClient.php`
- Create: `tests/Unit/OneCTransportErrorClassifierTest.php`

**Interfaces:**
- Consumes: `Throwable`, optional cURL handler context, and the configured timeout.
- Produces: `OneCTransportErrorClassifier::context(Throwable $exception, int $timeout): array{category: string, curl_errno?: int, timeout_seconds: int}`.

- [ ] Write failing tests for cURL codes 28 (timeout), 7 (connection refused), 6 (DNS), 35/60 (TLS), 52/56 (empty/reset connection), and an unknown exception (`network`).
- [ ] Run `php artisan test tests/Unit/OneCTransportErrorClassifierTest.php` and verify RED because the classifier does not exist.
- [ ] Implement the classifier using numeric cURL codes from the wrapped Guzzle exception handler context where available, with conservative message-pattern fallback only for tests/transports without handler context.
- [ ] Change `OneCApiClient::request()` to merge the safe classifier context into `OneCApiException`, while retaining the raw exception message only in Laravel log.
- [ ] Add `error_category => invalid_response` to exceptions raised when a successful HTTP response cannot be decoded into the expected array.
- [ ] Run the classifier tests and `php -l` for the two production files; verify GREEN.
- [ ] Commit with `feat: classify 1C transport errors`.

---

### Task 2: Human-readable Bitrix24 reasons

**Files:**
- Modify: `app/Services/BookingMonitoring/OneCBookingFailureClassifier.php`
- Modify: `app/Services/BookingMonitoring/BookingAvailabilityMessageFactory.php`
- Create: `tests/Unit/OneCBookingFailureClassifierTest.php`
- Modify: `tests/Unit/BookingMonitoringNotifierTest.php`

**Interfaces:**
- Consumes: `OneCBookingException::context()` containing `api_error.status`, `api_error.error_category`, `api_error.curl_errno`, and `api_error.timeout_seconds`.
- Produces: `OneCBookingFailureClassifier::safeReason()` with the exact approved Russian wording and a Bitrix24 line `Этап: Передача записи клиента в 1С`.

- [ ] Write failing data-provider tests for HTTP 502, 401, 403, 408, 429; `timeout`, `connection_refused`, `dns`, `tls`, `connection_reset`, `invalid_response`, `network`; and the neutral fallback.
- [ ] Add a test proving raw exception text, URL, response body, token, phone, and patient name are absent from the generated Bitrix24 message.
- [ ] Run both unit test files and verify RED on the current generic reason/missing stage.
- [ ] Implement status-first wording, then category wording, then neutral fallback; do not interpolate raw exception text.
- [ ] Add the fixed stage line to `BookingAvailabilityMessageFactory::bookingFailure()`.
- [ ] Run both unit test files and verify GREEN.
- [ ] Commit with `feat: explain 1C booking failures in Bitrix24`.

---

### Task 3: API contract and regression verification

**Files:**
- Modify: `tests/Feature/BookingWidgetApiContractTest.php`

**Interfaces:**
- Consumes: the existing `/api/v1/applications` endpoint and queue notification job.
- Produces: regression evidence that HTTP 502 becomes a detailed alert while the widget still gets the original safe HTTP 503 response and the failed application is deleted.

- [ ] Strengthen the existing real-booking technical-error test to assert the queued message contains `Этап: Передача записи клиента в 1С` and `Сервер 1С или промежуточный сервер вернул HTTP 502`.
- [ ] Run the focused feature test and verify RED before changing its mocked status from the old 500 expectation to the approved 502 wording.
- [ ] Make only the minimal fixture/assertion adjustment required by the approved behavior.
- [ ] Run the feature file plus all booking-monitoring unit/feature tests and verify GREEN.
- [ ] Run Pint on changed PHP files, `git diff --check`, PHP syntax checks, and the complete targeted regression suite.
- [ ] Commit with `test: verify detailed 1C booking alerts`.
