# 🔍 Проверка требований сервера через SSH

## 📋 Быстрая проверка всех требований

```bash
# Подключение к серверу
ssh root@your-server-ip

# Запуск полной проверки одной командой
echo "=== ПРОВЕРКА СЕРВЕРА ===" && \
echo "Hostname: $(hostname)" && \
echo "Uptime: $(uptime)" && \
echo "" && \
echo "=== ОПЕРАЦИОННАЯ СИСТЕМА ===" && \
cat /etc/os-release | grep -E "NAME|VERSION" && \
echo "" && \
echo "=== РЕСУРСЫ СЕРВЕРА ===" && \
echo "CPU:" && \
lscpu | grep -E "Model name|CPU\(s\):" && \
echo "" && \
echo "RAM:" && \
free -h && \
echo "" && \
echo "ДИСК:" && \
df -h && \
echo "" && \
echo "=== СЕТЬ ===" && \
echo "IP адреса:" && \
ip addr show | grep -E "inet " | grep -v "127.0.0.1" && \
echo "" && \
echo "=== АРХИТЕКТУРА ===" && \
uname -a
```

---

## 🖥️ Детальная проверка по компонентам

### 1. Операционная система

```bash
# Версия Ubuntu
cat /etc/os-release

# Ядро Linux
uname -r

# Архитектура (должна быть x86_64)
uname -m

# Проверка что это именно Ubuntu 22.04+
lsb_release -a
```

**Ожидаемый результат:**
```
Distributor ID: Ubuntu
Description:    Ubuntu 22.04.3 LTS
Release:        22.04
Codename:       jammy
```

### 2. Процессор

```bash
# Информация о CPU
lscpu

# Количество ядер
nproc

# Модель процессора
cat /proc/cpuinfo | grep "model name" | head -1

# Загрузка CPU
top -bn1 | grep "Cpu(s)"
```

**Минимальные требования:** 1+ ядер, желательно 2+

### 3. Оперативная память

```bash
# Общая информация о памяти
free -h

# Детальная информация
cat /proc/meminfo | grep -E "MemTotal|MemAvailable|SwapTotal"

# Использование памяти в процентах
free | awk 'NR==2{printf "Использовано: %d%% (%s/%s)\n", $3*100/$2, $3, $2}'
```

**Требования:**
- ✅ Минимум: 2GB RAM
- 🚀 Рекомендуется: 4GB+ RAM

### 4. Дисковое пространство

```bash
# Общее использование дисков
df -h

# Размер корневого раздела
df -h /

# Детальная информация о дисках
lsblk

# Тип файловой системы
mount | grep "on / type"

# Свободное место в человекочитаемом виде
df -h / | awk 'NR==2{print "Свободно:", $4, "из", $2, "(" $5, "используется)"}'
```

**Требования:**
- ✅ Минимум: 20GB свободного места
- 🚀 Рекомендуется: 50GB+ (SSD предпочтительно)

### 5. Сетевые настройки

```bash
# Все сетевые интерфейсы
ip addr show

# Публичный IP адрес
curl -s ifconfig.me && echo

# Внутренний IP
hostname -I

# Проверка DNS
nslookup google.com

# Открытые порты
ss -tulpn

# Проверка интернет-соединения
ping -c 3 8.8.8.8
```

### 6. Проверка домена (если есть)

```bash
# Проверка резолва домена на этот сервер
nslookup your-domain.com

# Проверка A записи
dig A your-domain.com

# Проверка доступности домена
curl -I http://your-domain.com
```

---

## 🔧 Проверка установленного ПО

### Текущие версии

```bash
# PHP (если установлен)
php --version 2>/dev/null || echo "PHP не установлен"

# Nginx (если установлен)
nginx -v 2>/dev/null || echo "Nginx не установлен"

# MySQL (если установлен)
mysql --version 2>/dev/null || echo "MySQL не установлен"

# Redis (если установлен)
redis-server --version 2>/dev/null || echo "Redis не установлен"

# Supervisor (если установлен)
supervisord --version 2>/dev/null || echo "Supervisor не установлен"

# Git
git --version 2>/dev/null || echo "Git не установлен"

# Curl
curl --version | head -1 2>/dev/null || echo "Curl не установлен"

# Composer (если установлен)
composer --version 2>/dev/null || echo "Composer не установлен"
```

### Активные сервисы

```bash
# Список всех сервисов
systemctl list-units --type=service --state=running

# Проверка ключевых сервисов (если установлены)
systemctl is-active nginx 2>/dev/null || echo "Nginx не запущен"
systemctl is-active mysql 2>/dev/null || echo "MySQL не запущен"  
systemctl is-active redis-server 2>/dev/null || echo "Redis не запущен"
systemctl is-active php8.2-fpm 2>/dev/null || echo "PHP-FPM не запущен"
systemctl is-active supervisor 2>/dev/null || echo "Supervisor не запущен"
```

---

## 📊 Скрипт автоматической проверки

Создайте файл для быстрой проверки:

```bash
# Создание скрипта проверки
cat > ~/server_check.sh << 'EOF'
#!/bin/bash

echo "🔍 ПРОВЕРКА ТРЕБОВАНИЙ СЕРВЕРА"
echo "================================"

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

check_requirement() {
    local description="$1"
    local command="$2"
    local requirement="$3"
    
    echo -n "Проверка $description... "
    
    if eval "$command"; then
        echo -e "${GREEN}✅ OK${NC} ($requirement)"
    else
        echo -e "${RED}❌ FAIL${NC} ($requirement)"
    fi
}

echo ""
echo "📋 ОПЕРАЦИОННАЯ СИСТЕМА"
check_requirement "Ubuntu 22.04+" "grep -q 'Ubuntu' /etc/os-release && grep -q '22\|23\|24' /etc/os-release" "Ubuntu 22.04 LTS или новее"

echo ""
echo "🖥️ АППАРАТНЫЕ РЕСУРСЫ"

# RAM проверка
RAM_MB=$(free -m | awk 'NR==2{print $2}')
check_requirement "RAM (${RAM_MB}MB)" "[ $RAM_MB -ge 2048 ]" "Минимум 2GB RAM"

# Диск проверка  
DISK_GB=$(df -BG / | awk 'NR==2{print $4}' | sed 's/G//')
check_requirement "Диск (${DISK_GB}GB свободно)" "[ $DISK_GB -ge 20 ]" "Минимум 20GB свободного места"

# CPU проверка
CPU_CORES=$(nproc)
check_requirement "CPU (${CPU_CORES} ядер)" "[ $CPU_CORES -ge 1 ]" "Минимум 1 ядро"

echo ""
echo "🌐 СЕТЬ"
check_requirement "Интернет соединение" "ping -c 1 -W 5 8.8.8.8 >/dev/null 2>&1" "Стабильное интернет соединение"
check_requirement "Публичный IP" "curl -s --connect-timeout 5 ifconfig.me >/dev/null 2>&1" "Публичный IP адрес"

echo ""
echo "🔧 БАЗОВЫЕ УТИЛИТЫ"
check_requirement "Git" "which git >/dev/null 2>&1" "Git для клонирования кода"
check_requirement "Curl" "which curl >/dev/null 2>&1" "Curl для загрузок"
check_requirement "Wget" "which wget >/dev/null 2>&1" "Wget для загрузок"

echo ""
echo "📊 ДЕТАЛЬНАЯ ИНФОРМАЦИЯ"
echo "================================"
echo "Hostname: $(hostname)"
echo "Uptime: $(uptime)"
echo "Kernel: $(uname -r)"
echo "Architecture: $(uname -m)"
echo "CPU Model: $(cat /proc/cpuinfo | grep 'model name' | head -1 | cut -d: -f2 | xargs)"
echo "Public IP: $(curl -s --connect-timeout 5 ifconfig.me 2>/dev/null || echo 'Недоступен')"
echo "Local IP: $(hostname -I | awk '{print $1}')"

echo ""
echo "🎯 ЗАКЛЮЧЕНИЕ"
echo "================================"
if [ $RAM_MB -ge 2048 ] && [ $DISK_GB -ge 20 ] && [ $CPU_CORES -ge 1 ]; then
    echo -e "${GREEN}✅ Сервер соответствует минимальным требованиям для развертывания${NC}"
else
    echo -e "${RED}❌ Сервер НЕ соответствует минимальным требованиям${NC}"
fi

if [ $RAM_MB -ge 4096 ] && [ $DISK_GB -ge 50 ] && [ $CPU_CORES -ge 2 ]; then
    echo -e "${GREEN}🚀 Сервер соответствует рекомендуемым требованиям${NC}"
fi

EOF

# Делаем скрипт исполняемым
chmod +x ~/server_check.sh

# Запускаем проверку
~/server_check.sh
```

---

## 🚨 Интерпретация результатов

### ✅ Хорошие показатели
```bash
# RAM: 4GB+
free -h | grep Mem: | awk '{print $2}'

# Диск: 50GB+ свободного места
df -h / | awk 'NR==2{print $4}'

# CPU: 2+ ядра
nproc

# Load Average: < 1.0 на ядро
uptime
```

### ⚠️ Предупреждения
- **RAM < 2GB**: Приложение может работать медленно
- **Диск < 20GB**: Недостаточно места для логов и обновлений  
- **Load Average > количества ядер**: Высокая нагрузка
- **Swap активно используется**: Нехватка RAM

### ❌ Критичные проблемы
- **Нет интернета**: Невозможна установка пакетов
- **Диск заполнен > 90%**: Риск сбоя системы
- **Старая версия Ubuntu**: Проблемы с безопасностью

---

## 🔧 Быстрые команды для мониторинга

```bash
# Мониторинг в реальном времени
watch -n 2 'free -h && echo "" && df -h && echo "" && uptime'

# Проверка производительности
htop  # (нужно установить: apt install htop)

# Проверка дискового I/O
iostat 1 5  # (нужно установить: apt install sysstat)

# Сетевая статистика
ss -tulpn | grep LISTEN
```

Используй эти команды для проверки сервера перед деплоем!
