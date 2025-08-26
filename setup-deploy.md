# 🚀 Настройка автоматического деплоя

Инструкция по настройке автоматического деплоя Laravel приложения с GitHub Actions.

## 📋 Что создано

1. **GitHub Actions workflow** (`.github/workflows/deploy.yml`)
2. **Деплой скрипт** (`deploy.sh`) для ручного запуска
3. **Эта инструкция** по настройке

## 🔑 Этап 1: Настройка SSH ключей

### На сервере

```bash
# Подключитесь к серверу
ssh root@your-server-ip

# Создайте SSH ключ для деплоя (без пароля)
ssh-keygen -t rsa -b 4096 -C "deploy@medical-center" -f /root/.ssh/deploy_key -N ""

# Добавьте публичный ключ в authorized_keys для пользователя laravel
cat /root/.ssh/deploy_key.pub >> /home/laravel/.ssh/authorized_keys

# Скопируйте приватный ключ (понадобится для GitHub Secrets)
cat /root/.ssh/deploy_key
```

### В GitHub репозитории

1. Перейдите в **Settings** → **Secrets and variables** → **Actions**
2. Создайте следующие секреты:

```
HOST = IP адрес вашего сервера
USERNAME = root
SSH_KEY = ваш приватный SSH ключ для root
PORT = 22 (или ваш SSH порт)
```

## 🛠️ Этап 2: Настройка деплой скрипта на сервере

```bash
# Скопируйте деплой скрипт на сервер
scp deploy.sh root@your-server-ip:/usr/local/bin/deploy-medical-center.sh

# Сделайте его исполняемым
ssh root@your-server-ip
chmod +x /usr/local/bin/deploy-medical-center.sh

# Создайте директорию для бэкапов
mkdir -p /var/www/backups
```

## 🔄 Этап 3: Настройка webhook (опционально)

### Создание webhook endpoint

```bash
# На сервере создайте файл webhook
nano /var/www/webhook-deploy.php
```

```php
<?php
// Простой webhook для автоматического деплоя
// Файл: /var/www/webhook-deploy.php

$secret = 'your-webhook-secret-here'; // Замените на ваш секрет
$payload = file_get_contents('php://input');
$signature = hash_hmac('sha256', $payload, $secret);

// Проверяем подпись GitHub
$github_signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if (!hash_equals($signature, str_replace('sha256=', '', $github_signature))) {
    http_response_code(403);
    exit('Invalid signature');
}

$data = json_decode($payload, true);

// Проверяем что это push в main/master ветку
if ($data['ref'] === 'refs/heads/main' || $data['ref'] === 'refs/heads/master') {
    // Логируем событие
    file_put_contents('/tmp/webhook.log', date('Y-m-d H:i:s') . " - Deploy triggered\n", FILE_APPEND);
    
    // Запускаем деплой в фоне
    exec('sudo /usr/local/bin/deploy-medical-center.sh > /tmp/deploy.log 2>&1 &');
    
    echo "Deploy started";
} else {
    echo "Not a main branch push";
}
?>
```

### Настройка Nginx для webhook

```bash
# Добавьте location в конфигурацию Nginx
nano /etc/nginx/sites-available/medical-center
```

Добавьте в секцию server:

```nginx
# Webhook для автоматического деплоя
location /webhook-deploy {
    try_files $uri /webhook-deploy.php$is_args$args;
}

location ~ ^/webhook-deploy\.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /var/www/webhook-deploy.php;
    include fastcgi_params;
}
```

```bash
# Перезагрузите Nginx
systemctl reload nginx
```

### Настройка webhook в GitHub

1. Перейдите в **Settings** → **Webhooks** → **Add webhook**
2. Заполните:
   - **Payload URL**: `https://your-domain.com/webhook-deploy`
   - **Content type**: `application/json`
   - **Secret**: ваш секрет из webhook-deploy.php
   - **Events**: Just the push event

## ⚙️ Этап 4: Настройка GitHub Actions

### Обновите секреты в GitHub

Добавьте дополнительный секрет:
```
DOWNLOAD_URL = https://api.github.com/repos/your-username/adminzrenie-laravel/actions/artifacts
```

### Настройте доступ к GitHub API

Создайте Personal Access Token:
1. GitHub → **Settings** → **Developer settings** → **Personal access tokens** → **Tokens (classic)**
2. Создайте токен с правами `repo` и `actions:read`
3. Добавьте в GitHub Secrets как `GITHUB_TOKEN`

## 🧪 Этап 5: Тестирование

### Тест 1: Ручной деплой

```bash
# На сервере
sudo /usr/local/bin/deploy-medical-center.sh
```

### Тест 2: Автоматический деплой

1. Сделайте изменения в коде
2. Закоммитьте в main/master ветку
3. Запушьте в GitHub
4. Проверьте выполнение в **Actions** tab

### Тест 3: Webhook (если настроен)

```bash
# Тест webhook вручную
curl -X POST https://your-domain.com/webhook-deploy \
  -H "Content-Type: application/json" \
  -H "X-Hub-Signature-256: sha256=test" \
  -d '{"ref":"refs/heads/main"}'
```

## 📊 Мониторинг деплоев

### Проверка логов

```bash
# Логи деплоя
tail -f /tmp/deploy.log

# Логи webhook
tail -f /tmp/webhook.log

# Логи Laravel
tail -f /var/www/medical-center/storage/logs/laravel.log

# Логи Nginx
tail -f /var/log/nginx/medical-center_error.log
```

### Проверка статуса сервисов

```bash
# Статус всех сервисов
sudo systemctl status nginx php8.2-fpm mysql redis-server supervisor

# Статус воркеров
sudo supervisorctl status
```

## 🆘 Откат к предыдущей версии

```bash
# Автоматический откат
sudo /usr/local/bin/deploy-medical-center.sh --rollback

# Или ручной откат
cd /var/www/backups
ls -la  # Найдите нужный бэкап
sudo rm -rf /var/www/medical-center
sudo cp -r medical-center-backup-YYYYMMDD_HHMMSS /var/www/medical-center
sudo systemctl restart php8.2-fpm
sudo supervisorctl restart laravel-worker:*
```

## 🔧 Настройка уведомлений

### Telegram уведомления о деплое

Добавьте в конец деплой скрипта:

```bash
# Уведомление в Telegram
send_telegram_notification() {
    local message="$1"
    local bot_token="YOUR_BOT_TOKEN"
    local chat_id="YOUR_CHAT_ID"
    
    curl -s -X POST "https://api.telegram.org/bot${bot_token}/sendMessage" \
        -d chat_id="${chat_id}" \
        -d text="${message}" \
        -d parse_mode="HTML" > /dev/null
}

# В конце функции main()
send_telegram_notification "✅ <b>Medical Center</b> успешно обновлен до версии $(git rev-parse --short HEAD)"
```

### Email уведомления

Настройте в Laravel:

```php
// В AppServiceProvider или создайте Event/Listener
Event::listen('deployment.completed', function ($version) {
    Mail::to('admin@your-domain.com')->send(new DeploymentNotification($version));
});
```

## 📋 Чек-лист настройки

### ✅ SSH и доступы
- [ ] SSH ключи созданы
- [ ] Ключи добавлены в GitHub Secrets
- [ ] Доступ с GitHub Actions на сервер работает

### ✅ Скрипты
- [ ] deploy.sh скопирован на сервер
- [ ] Скрипт исполняемый и работает
- [ ] Права доступа настроены

### ✅ GitHub Actions
- [ ] Workflow файл создан
- [ ] Все секреты настроены
- [ ] Первый деплой прошел успешно

### ✅ Webhook (опционально)
- [ ] webhook-deploy.php создан
- [ ] Nginx настроен
- [ ] GitHub webhook настроен
- [ ] Тестирование прошло успешно

### ✅ Мониторинг
- [ ] Логирование работает
- [ ] Уведомления настроены
- [ ] Бэкапы создаются

## 🎯 Результат

После настройки у вас будет:

1. **Автоматический деплой** при push в main/master
2. **Безопасное обновление** с резервными копиями
3. **Атомарная замена** без простоя
4. **Автоматический откат** при ошибках
5. **Мониторинг и уведомления**

Деплой будет происходить автоматически при каждом обновлении кода в GitHub! 🚀
