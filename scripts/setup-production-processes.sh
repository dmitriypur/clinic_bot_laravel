#!/usr/bin/env bash

set -euo pipefail

PROCESSES_APP_DIR="${MEDICAL_CENTER_APP_DIR:-/var/www/medical-center}"
PROCESSES_USER="${MEDICAL_CENTER_PROCESS_USER:-www-data}"
PROCESSES_PHP_BIN="$(command -v php)"
PROCESSES_WORKER_WAIT_SECONDS=30

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

PROCESSES_WORKERS_RUNNING=false
PROCESSES_WORKER_STATUS=""

for ((PROCESSES_WORKER_ATTEMPT = 1; PROCESSES_WORKER_ATTEMPT <= PROCESSES_WORKER_WAIT_SECONDS; PROCESSES_WORKER_ATTEMPT++)); do
    if PROCESSES_WORKER_STATUS="$(supervisorctl status 'laravel-worker:*' 2>&1)" \
        && [[ -n "${PROCESSES_WORKER_STATUS}" ]] \
        && ! grep -qv 'RUNNING' <<< "${PROCESSES_WORKER_STATUS}"; then
        PROCESSES_WORKERS_RUNNING=true
        break
    fi

    if ((PROCESSES_WORKER_ATTEMPT < PROCESSES_WORKER_WAIT_SECONDS)); then
        sleep 1
    fi
done

if [[ "${PROCESSES_WORKERS_RUNNING}" != true ]]; then
    echo "${PROCESSES_WORKER_STATUS}"
    echo "ERROR: Laravel queue worker не перешли в состояние RUNNING за ${PROCESSES_WORKER_WAIT_SECONDS} секунд."
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
