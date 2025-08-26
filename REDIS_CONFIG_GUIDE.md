# 🔴 Полное руководство по настройке Redis

## 📋 Обзор

Redis используется в Laravel приложении для:
- 🚀 **Кеширование** (sessions, cache, views)
- 📬 **Очереди задач** (queue jobs, email sending)
- 💾 **Временное хранение** (rate limiting, locks)

---

## 🛠️ Основные настройки Redis

### 📁 Расположение конфигурационного файла

```bash
# Ubuntu/Debian
/etc/redis/redis.conf

# CentOS/RHEL
/etc/redis.conf

# Поиск конфига
find /etc -name "*redis*" -type f 2>/dev/null
```

### 🔧 Структура конфигурации

```bash
# Редактирование основного конфига
sudo nano /etc/redis/redis.conf
```

---

## 🌐 Сетевые настройки

### Привязка к интерфейсам (bind)

```ini
# Только localhost (безопасно для продакшена)
bind 127.0.0.1 ::1

# Все интерфейсы (ОПАСНО без дополнительной защиты)
# bind 0.0.0.0

# Конкретные IP адреса
# bind 192.168.1.100 10.0.0.1
```

**Объяснение:**
- `127.0.0.1` - IPv4 localhost
- `::1` - IPv6 localhost
- Привязка только к localhost означает, что Redis доступен только с этого сервера

### Порт подключения

```ini
# Стандартный порт Redis
port 6379

# Отключение TCP порта (только Unix socket)
# port 0
```

### Unix Socket (альтернатива TCP)

```ini
# Путь к Unix socket
# unixsocket /var/run/redis/redis.sock

# Права доступа к socket
# unixsocketperm 770
```

---

## 🔒 Безопасность

### Protected Mode

```ini
# Защищенный режим (рекомендуется yes)
protected-mode yes
```

**Когда protected-mode включен:**
- Redis принимает подключения только с localhost
- Если bind не настроен, Redis работает только через localhost
- Блокирует внешние подключения без пароля

### Пароль аутентификации

```ini
# Установка пароля (раскомментировать и изменить)
# requirepass your_strong_password_here

# Пример:
requirepass MyStr0ngR3d1sP@ssw0rd2024
```

**После установки пароля в Laravel .env:**
```env
REDIS_PASSWORD=MyStr0ngR3d1sP@ssw0rd2024
```

### Переименование опасных команд

```ini
# Отключение опасных команд
rename-command FLUSHDB ""
rename-command FLUSHALL ""
rename-command SHUTDOWN SHUTDOWN_SECRET_KEY
rename-command CONFIG CONFIG_SECRET_KEY

# Или переименование
# rename-command FLUSHDB FLUSH_DB_SAFE
```

---

## 💾 Настройки сохранения данных

### Автоматическое сохранение (RDB)

```ini
# Сохранение данных на диск
save 900 1      # Если за 15 минут изменился 1+ ключ
save 300 10     # Если за 5 минут изменилось 10+ ключей  
save 60 10000   # Если за 1 минуту изменилось 10000+ ключей

# Отключение автосохранения
# save ""
```

**Настройки для разных нагрузок:**

```ini
# Низкая нагрузка (редкие изменения)
save 3600 1
save 1800 10
save 300 100

# Средняя нагрузка (умеренные изменения)
save 900 1
save 300 10
save 60 10000

# Высокая нагрузка (частые изменения)
save 300 1
save 60 10
save 30 1000
```

### Настройки RDB файлов

```ini
# Имя файла дампа
dbfilename dump.rdb

# Директория для сохранения
dir /var/lib/redis

# Сжатие RDB файлов
rdbcompression yes

# Проверка целостности
rdbchecksum yes

# Остановка записи при ошибке сохранения
stop-writes-on-bgsave-error yes
```

### AOF (Append Only File) - альтернатива RDB

```ini
# Включение AOF
appendonly yes

# Имя AOF файла
appendfilename "appendonly.aof"

# Частота синхронизации
appendfsync everysec    # Каждую секунду (рекомендуется)
# appendfsync always    # После каждой команды (медленно)
# appendfsync no        # Зависит от ОС (быстро, но рискованно)

# Перезапись AOF файла при росте
auto-aof-rewrite-percentage 100
auto-aof-rewrite-min-size 64mb
```

---

## 🧠 Управление памятью

### Лимиты памяти

```ini
# Максимальный объем памяти
maxmemory 256mb          # Для небольших проектов
# maxmemory 512mb        # Для средних проектов  
# maxmemory 1gb          # Для больших проектов

# Политика вытеснения при достижении лимита
maxmemory-policy allkeys-lru

# Количество ключей для анализа при вытеснении
maxmemory-samples 5
```

### Политики вытеснения (maxmemory-policy)

```ini
# Политики для всех ключей
allkeys-lru          # Удаляет наименее используемые ключи
allkeys-lfu          # Удаляет наименее часто используемые
allkeys-random       # Удаляет случайные ключи

# Политики только для ключей с TTL
volatile-lru         # LRU только среди ключей с expiration
volatile-lfu         # LFU только среди ключей с expiration  
volatile-random      # Случайно среди ключей с expiration
volatile-ttl         # Удаляет ключи с наименьшим TTL

# Никогда не удалять (вернет ошибку при нехватке памяти)
noeviction
```

**Рекомендации для Laravel:**
- `allkeys-lru` - универсальный выбор
- `volatile-lru` - если четко управляете TTL

---

## ⚡ Производительность

### TCP настройки

```ini
# Размер TCP backlog
tcp-backlog 511

# TCP keepalive
tcp-keepalive 300

# Таймаут клиентов (0 = без таймаута)
timeout 0
```

### Настройки клиентов

```ini
# Максимальное количество подключений
maxclients 10000

# Буфер вывода для обычных клиентов
client-output-buffer-limit normal 0 0 0

# Буфер для slave реплик
client-output-buffer-limit replica 256mb 64mb 60

# Буфер для pub/sub
client-output-buffer-limit pubsub 32mb 8mb 60
```

### База данных

```ini
# Количество баз данных (по умолчанию 16)
databases 16

# База по умолчанию в Laravel
# DB 0 - cache
# DB 1 - sessions  
# DB 2 - queues
```

---

## 📊 Логирование и мониторинг

### Уровни логирования

```ini
# Уровень логов
loglevel notice

# Файл логов
logfile /var/log/redis/redis-server.log

# Логирование в syslog
# syslog-enabled yes
# syslog-ident redis
```

**Уровни логирования:**
- `debug` - максимум информации
- `verbose` - много информации
- `notice` - важные события (рекомендуется)
- `warning` - только предупреждения и ошибки

### Медленные запросы

```ini
# Логирование медленных команд (в микросекундах)
slowlog-log-slower-than 10000  # 10ms

# Размер лога медленных команд
slowlog-max-len 128
```

---

## 🔧 Конфигурация для Laravel

### Оптимальная настройка для production

```ini
# ================ ОСНОВНЫЕ НАСТРОЙКИ ================

# Сеть и безопасность
bind 127.0.0.1 ::1
protected-mode yes
port 6379
tcp-backlog 511
timeout 0
tcp-keepalive 300

# Аутентификация
requirepass YourStrongPasswordHere2024

# ================ ПАМЯТЬ ================

# Лимит памяти (настройте под ваш сервер)
maxmemory 512mb
maxmemory-policy allkeys-lru
maxmemory-samples 5

# ================ СОХРАНЕНИЕ ================

# RDB сохранение
save 900 1
save 300 10  
save 60 10000

# RDB настройки
rdbcompression yes
rdbchecksum yes
dbfilename dump.rdb
dir /var/lib/redis
stop-writes-on-bgsave-error yes

# AOF для критичных данных (опционально)
appendonly no
appendfilename "appendonly.aof"
appendfsync everysec

# ================ ПРОИЗВОДИТЕЛЬНОСТЬ ================

# Клиенты
maxclients 10000
databases 16

# Буферы
client-output-buffer-limit normal 0 0 0
client-output-buffer-limit replica 256mb 64mb 60
client-output-buffer-limit pubsub 32mb 8mb 60

# ================ ЛОГИРОВАНИЕ ================

# Логи
loglevel notice
logfile /var/log/redis/redis-server.log

# Медленные запросы
slowlog-log-slower-than 10000
slowlog-max-len 128

# ================ ОПТИМИЗАЦИЯ ================

# Отключение проблемных команд
rename-command FLUSHDB ""
rename-command FLUSHALL ""
rename-command DEBUG ""
```

---

## 🎯 Настройка Laravel для Redis

### config/database.php

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),
    
    'options' => [
        'cluster' => env('REDIS_CLUSTER', 'redis'),
        'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
    ],

    'default' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
    ],

    'cache' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_CACHE_DB', '1'),
    ],

    'session' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_SESSION_DB', '2'),
    ],

    'queue' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_QUEUE_DB', '3'),
    ],
],
```

### .env настройки

```env
# Redis настройки
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=YourStrongPasswordHere2024
REDIS_PORT=6379

# Разделение по базам данных
REDIS_DB=0              # Default
REDIS_CACHE_DB=1        # Cache
REDIS_SESSION_DB=2      # Sessions  
REDIS_QUEUE_DB=3        # Queues

# Использование Redis
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

---

## 🔍 Мониторинг и диагностика

### Проверка статуса Redis

```bash
# Статус сервиса
sudo systemctl status redis-server

# Тест подключения
redis-cli ping

# С паролем
redis-cli -a YourPassword ping

# Информация о сервере
redis-cli info

# Использование памяти
redis-cli info memory

# Статистика команд
redis-cli info commandstats

# Медленные запросы
redis-cli slowlog get 10
```

### Мониторинг в реальном времени

```bash
# Мониторинг команд
redis-cli monitor

# Статистика каждые 2 секунды
redis-cli --stat -i 2

# Информация о клиентах
redis-cli client list

# Размер баз данных
redis-cli info keyspace
```

### Полезные команды для администрирования

```bash
# Список всех ключей (ОСТОРОЖНО на production!)
redis-cli keys "*"

# Информация о конкретном ключе
redis-cli type cache:key
redis-cli ttl cache:key

# Очистка конкретной базы данных
redis-cli -n 1 flushdb

# Принудительное сохранение
redis-cli bgsave

# Время последнего сохранения
redis-cli lastsave

# Конфигурация на лету
redis-cli config get "*"
redis-cli config set maxmemory 1gb
redis-cli config rewrite
```

---

## 🚨 Troubleshooting

### Частые проблемы и решения

#### 1. Redis не запускается

```bash
# Проверка логов
sudo tail -f /var/log/redis/redis-server.log

# Проверка конфигурации
sudo redis-server /etc/redis/redis.conf --test-config

# Права доступа
sudo chown redis:redis /var/lib/redis
sudo chmod 755 /var/lib/redis
```

#### 2. Ошибки подключения

```bash
# Проверка что Redis слушает
sudo netstat -tulpn | grep :6379

# Тест локального подключения
telnet 127.0.0.1 6379

# Проверка файрвола
sudo ufw status
```

#### 3. Проблемы с памятью

```bash
# Текущее использование
redis-cli info memory

# Очистка неиспользуемой памяти
redis-cli memory purge

# Анализ использования
redis-cli --bigkeys
```

#### 4. Медленная работа

```bash
# Анализ медленных команд
redis-cli slowlog get 20

# Статистика операций
redis-cli info stats

# Проверка нагрузки
top -p $(pgrep redis-server)
```

---

## 📈 Оптимизация производительности

### Для разных размеров проектов

#### Малый проект (< 10k пользователей)
```ini
maxmemory 256mb
maxmemory-policy allkeys-lru
save 900 1 300 10 60 1000
maxclients 1000
```

#### Средний проект (10k-100k пользователей)
```ini
maxmemory 1gb
maxmemory-policy allkeys-lru  
save 600 1 300 10 60 10000
maxclients 5000
appendonly yes
```

#### Большой проект (100k+ пользователей)
```ini
maxmemory 4gb
maxmemory-policy allkeys-lru
save 300 1 60 10 30 10000
maxclients 20000
appendonly yes
tcp-backlog 2048
```

### Советы по оптимизации

1. **Используйте TTL** для временных данных
2. **Мониторьте память** регулярно
3. **Настройте логирование** медленных запросов
4. **Разделяйте данные** по базам (cache, sessions, queues)
5. **Регулярно анализируйте** большие ключи

---

## 🎯 Заключение

Правильная настройка Redis критична для производительности Laravel приложения. Основные принципы:

- ✅ **Безопасность первом месте** (bind, protected-mode, пароли)
- ✅ **Управление памятью** (maxmemory, eviction policy)
- ✅ **Надежность данных** (save, AOF для критичных данных)
- ✅ **Мониторинг** (логи, медленные запросы, метрики)
- ✅ **Оптимизация** под конкретную нагрузку

С этими настройками Redis будет стабильно работать в production среде! 🚀
