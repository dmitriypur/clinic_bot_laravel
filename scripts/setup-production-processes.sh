#!/usr/bin/env bash

set -euo pipefail

PROCESSES_APP_DIR="${MEDICAL_CENTER_APP_DIR:-/var/www/medical-center}"
PROCESSES_USER="${MEDICAL_CENTER_PROCESS_USER:-www-data}"
PROCESSES_PHP_BIN="$(command -v php)"

if [[ "${EUID}" -ne 0 ]]; then
    echo "ERROR: настройка cron и Supervisor должна выполняться от root."
    exit 1
fi

if [[ ! -f "${PROCESSES_APP_DIR}/artisan" ]]; then
    echo "ERROR: artisan не найден в ${PROCESSES_APP_DIR}."
    exit 1
fi

if ! id "${PROCESSES_USER}" >/dev/null 2>&1; then
    echo "ERROR: системный пользователь ${PROCESSES_USER} не найден."
    exit 1
fi

if ! command -v supervisorctl >/dev/null 2>&1; then
    echo "ERROR: существующий Supervisor не найден. Его конфигурация не изменялась."
    exit 1
fi

if ! command -v cron >/dev/null 2>&1; then
    echo "ERROR: системный cron не найден."
    exit 1
fi

# Проверяет доступ к настроенной очереди: к таблице jobs для database или к Redis.
cd "${PROCESSES_APP_DIR}"
"${PROCESSES_PHP_BIN}" artisan queue:monitor default --max=1000000000

if command -v systemctl >/dev/null 2>&1; then
    if ! systemctl is-active --quiet cron; then
        echo "ERROR: системный cron не запущен. Сервис не изменялся."
        exit 1
    fi
else
    if ! service cron status >/dev/null 2>&1; then
        echo "ERROR: системный cron не запущен. Сервис не изменялся."
        exit 1
    fi
fi

PROCESSES_WORKER_STATUS="$(supervisorctl status 'laravel-worker:*' 2>&1)" || {
    echo "ERROR: существующие Laravel queue worker не найдены."
    exit 1
}

if [[ -z "${PROCESSES_WORKER_STATUS}" ]] || echo "${PROCESSES_WORKER_STATUS}" | grep -qv 'RUNNING'; then
    echo "ERROR: не все существующие Laravel queue worker находятся в состоянии RUNNING."
    exit 1
fi

install -d -o "${PROCESSES_USER}" -g "${PROCESSES_USER}" "${PROCESSES_APP_DIR}/storage/logs"
touch "${PROCESSES_APP_DIR}/storage/logs/scheduler.log"
chown "${PROCESSES_USER}:${PROCESSES_USER}" "${PROCESSES_APP_DIR}/storage/logs/scheduler.log"

cat > /etc/cron.d/medical-center-scheduler <<EOF
* * * * * ${PROCESSES_USER} cd ${PROCESSES_APP_DIR} && ${PROCESSES_PHP_BIN} artisan schedule:run >> ${PROCESSES_APP_DIR}/storage/logs/scheduler.log 2>&1
EOF
chmod 0644 /etc/cron.d/medical-center-scheduler

if ! grep -q 'artisan schedule:run' /etc/cron.d/medical-center-scheduler; then
    echo "ERROR: Laravel Scheduler не добавлен в cron."
    exit 1
fi

if ! "${PROCESSES_PHP_BIN}" "${PROCESSES_APP_DIR}/artisan" schedule:list | grep -q 'booking:check-availability'; then
    echo "ERROR: расписание проверки записи не зарегистрировано в Laravel."
    exit 1
fi

echo "Laravel Scheduler добавлен; существующая конфигурация Supervisor не изменялась."
