#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
umask 077

booking_port="${1:-8011}"
booking_demo_dir="$(mktemp -d "${TMPDIR:-/tmp}/booking-postman.XXXXXX")"
touch "$booking_demo_dir/booking.sqlite"

export APP_ENV=local APP_DEBUG=false
export APP_CONFIG_CACHE="$booking_demo_dir/config.php" APP_ROUTES_CACHE="$booking_demo_dir/routes.php"
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export DB_CONNECTION=sqlite DB_URL="" DB_DATABASE="$booking_demo_dir/booking.sqlite"
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
export BOOKING_DEMO_DATE="$(TZ=Europe/Moscow date +%F)"

php artisan migrate --no-interaction
php artisan db:seed --class=BookingIntegrationDemoSeeder --no-interaction

export BOOKING_INTEGRATION_CLIENTS="$(php -r '
    [$path, $port] = array_slice($argv, 1);
    $today = new DateTimeImmutable(getenv("BOOKING_DEMO_DATE"), new DateTimeZone("Europe/Moscow"));
    $values = [
        "base_url" => "http://127.0.0.1:".$port,
        "yesterday" => $today->modify("-1 day")->format("Y-m-d"),
        "today" => $today->format("Y-m-d"),
        "tomorrow" => $today->modify("+1 day")->format("Y-m-d"),
        "day_after_tomorrow" => $today->modify("+2 days")->format("Y-m-d"),
        "fallback_end" => $today->modify("+31 days")->format("Y-m-d"),
    ];
    $clients = [];
    foreach ([
        "postman" => ["booking_token", ["catalog:read", "availability:read"]],
        "catalog-demo" => ["catalog_token", ["catalog:read"]],
    ] as $clientId => [$key, $scopes]) {
        $token = $clientId.".".bin2hex(random_bytes(32));
        $values[$key] = $token;
        $clients[$clientId] = ["token_hash" => hash("sha256", $token), "scopes" => $scopes];
    }

    $variables = [];
    foreach ($values as $key => $value) {
        $variables[] = [
            "key" => $key, "value" => $value, "enabled" => true,
            "type" => str_ends_with($key, "_token") ? "secret" : "default",
        ];
    }

    $environment = [
        "name" => "Laravel Booking · local demo",
        "values" => $variables,
        "_postman_variable_scope" => "environment",
    ];
    if (file_put_contents($path, json_encode($environment, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
        exit(1);
    }

    echo json_encode($clients, JSON_THROW_ON_ERROR);
' "$booking_demo_dir/booking.postman_environment.json" "$booking_port")"

printf '\nPostman environment: %s\nTemporary SQLite database: %s\n\n' \
  "$booking_demo_dir/booking.postman_environment.json" "$DB_DATABASE"

exec php artisan serve --host=127.0.0.1 --port="$booking_port" --tries=1 --no-reload
