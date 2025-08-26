# Исправление ошибки Vite "Cannot find package '@vitejs/plugin-vue'"

## Проблема
При сборке фронтенда на удаленном сервере возникает ошибка:
```
Cannot find package '@vitejs/plugin-vue' imported from vite.config.js
```

## Причины
1. ❌ Не установлены Node.js зависимости
2. ❌ Неправильная структура зависимостей в package.json
3. ❌ Отсутствует файл package-lock.json
4. ❌ Устаревшая версия Node.js/npm

## Решение

### Шаг 1: Проверка окружения
```bash
# Проверка версий
node --version   # Рекомендуется >= 20.19.0 (минимум 18)
npm --version    # Должно быть >= 9

# Проверка структуры проекта
ls -la package.json vite.config.js
```

**⚠️ Важно:** Vite 7.x требует Node.js 20.19+ или 22.12+, но работает и на 20.17.0 с предупреждениями.

### Шаг 2: Очистка и переустановка зависимостей
```bash
# Удаление старых файлов
rm -rf node_modules/
rm -f package-lock.json

# Установка зависимостей
npm install

# Или если есть проблемы с правами
npm install --unsafe-perm=true --allow-root
```

### Шаг 3: Альтернативная установка (если npm не работает)
```bash
# Используйте yarn (если установлен)
yarn install
yarn build

# Или pnpm
pnpm install
pnpm build
```

### Шаг 4: Сборка проекта
```bash
# Сборка для production
npm run build

# Проверка результата
ls -la public/build/
```

### Шаг 5: Если проблема сохраняется

#### Ручная установка отсутствующих пакетов:
```bash
npm install --save-dev @vitejs/plugin-vue@^5.2.4
npm install --save-dev vue@^3.5.20
npm install --save-dev vite@^7.0.4
npm install --save-dev laravel-vite-plugin@^2.0.0
```

#### Проверка конфигурации vite.config.js:
```javascript
import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.js'],
            refresh: true,
        }),
        vue(),
    ],
})
```

## Исправленная структура package.json

Убедитесь что в вашем package.json правильная структура зависимостей:

```json
{
    "private": true,
    "type": "module",
    "scripts": {
        "build": "vite build",
        "dev": "vite"
    },
    "devDependencies": {
        "@tailwindcss/vite": "^4.0.0",
        "@vitejs/plugin-vue": "^5.2.4",
        "autoprefixer": "^10.4.21",
        "axios": "^1.11.0",
        "concurrently": "^9.0.1",
        "laravel-vite-plugin": "^2.0.0",
        "postcss": "^8.5.6",
        "tailwindcss": "^3.4.17",
        "vite": "^7.0.4",
        "vue": "^3.5.20"
    },
    "dependencies": {
        "@inertiajs/vue3": "^2.1.2"
    }
}
```

## Автоматизированное исправление

### Скрипт для удаленного сервера:
```bash
#!/bin/bash

echo "=== ИСПРАВЛЕНИЕ VITE BUILD ==="
echo ""

echo "1. Проверка версий:"
node --version
npm --version
echo ""

echo "2. Очистка старых файлов:"
rm -rf node_modules/
rm -f package-lock.json
echo "✅ Очистка завершена"
echo ""

echo "3. Установка зависимостей:"
npm install --unsafe-perm=true --allow-root
echo ""

echo "4. Проверка установки @vitejs/plugin-vue:"
npm list @vitejs/plugin-vue || echo "❌ Пакет не найден"
echo ""

echo "5. Сборка проекта:"
npm run build
echo ""

echo "6. Проверка результата:"
if [ -d "public/build" ]; then
    echo "✅ Сборка успешна"
    ls -la public/build/ | head -5
else
    echo "❌ Сборка не удалась"
fi

echo ""
echo "=== ЗАВЕРШЕНО ==="
```

## Альтернативные решения

### 1. Если нет Node.js на сервере
Соберите фронтенд локально и загрузите:
```bash
# Локально
npm run build
zip -r build.zip public/build/

# На сервере
unzip build.zip
```

### 2. Использование Docker для сборки
```dockerfile
FROM node:18 as build
WORKDIR /app
COPY package*.json ./
RUN npm install
COPY . .
RUN npm run build

FROM php:8.3-fpm
COPY --from=build /app/public/build /var/www/html/public/build
```

### 3. CI/CD сборка
Настройте автоматическую сборку в GitHub Actions или GitLab CI.

## Проверка конфигурации веб-сервера

### Apache
Убедитесь что статические файлы отдаются правильно:
```apache
<Directory "/var/www/medical-center/public">
    AllowOverride All
    Require all granted
</Directory>
```

### Nginx
```nginx
location /build/ {
    alias /var/www/medical-center/public/build/;
    expires 1y;
    add_header Cache-Control "public, immutable";
}
```

## Отладка проблем

### 1. Проверка содержимого node_modules:
```bash
ls -la node_modules/@vitejs/
ls -la node_modules/vue/
```

### 2. Проверка симлинков:
```bash
file node_modules/@vitejs/plugin-vue
```

### 3. Принудительная очистка npm кэша:
```bash
npm cache clean --force
```

### 4. Проверка прав доступа:
```bash
chmod -R 755 node_modules/
chown -R $USER:$USER node_modules/
```

## Контакты для поддержки
Если проблема не решается:
1. Проверьте логи npm: `npm install --verbose`
2. Проверьте доступность репозиториев npm
3. Убедитесь что на сервере достаточно места и памяти
