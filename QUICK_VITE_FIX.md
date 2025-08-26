# Быстрое исправление ошибки Vite на удаленном сервере

## Проблема
```
Cannot find package '@vitejs/plugin-vue' imported from vite.config.js
```

## Быстрое решение

### Вариант 1: Автоматический скрипт
```bash
# На удаленном сервере
./fix-vite-build.sh
```

### Вариант 2: Ручные команды
```bash
# 1. Очистка
rm -rf node_modules/ package-lock.json

# 2. Переустановка
npm install --unsafe-perm=true --allow-root

# 3. Сборка
npm run build

# 4. Проверка результата
ls -la public/build/
```

### Вариант 3: Если npm не работает
```bash
# Используйте yarn
yarn install
yarn build

# Или соберите локально и загрузите
# Локально: npm run build && zip -r build.zip public/build/
# На сервере: unzip build.zip
```

## Что изменено в проекте
1. ✅ `@vitejs/plugin-vue` перенесен в `devDependencies`
2. ✅ `vue` перенесен в `devDependencies` 
3. ✅ Создан скрипт автоматического исправления
4. ✅ Создана подробная документация

## Требования к серверу
- Node.js >= 18 (рекомендуется >= 20.19.0)
- npm >= 9
- Достаточно места на диске для node_modules

## Проверка успешной сборки
После выполнения команд должны появиться файлы:
- `public/build/manifest.json`
- `public/build/assets/app-*.css`
- `public/build/assets/app-*.js`
