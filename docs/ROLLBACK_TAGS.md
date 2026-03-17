# Rollback Tags

Этот документ нужен для быстрого и безопасного отката к проверенным состояниям приложения.

## Актуальные rollback-теги

### `prod-stable-2026-03-11`

Точка отката на проверенное стабильное состояние до фикса синхронизации 1С.

Что включает:
- проверенные коммиты по рефакторингу, которые уже были в `main`

Что не включает:
- фикс `fix(onec): delete stale slots missing from schedule batch`
- фикс `perf(calendar): preload applications for slot events`

Когда использовать:
- если нужно откатиться к более старому стабильному состоянию до изменений по 1С и календарю

### `prod-stable-post-onec-2026-03-17`

Точка отката на проверенное состояние после фикса 1С, но до ускорения календаря.

Что включает:
- фикс `06994da` `fix(onec): delete stale slots missing from schedule batch`

Что не включает:
- фикс `426a19a` `perf(calendar): preload applications for slot events`

Когда использовать:
- если нужно откатить только ускорение календаря
- если фикс 1С должен остаться в проде

### `tested-calendar-perf-2026-03-17`

Точка отката на текущее проверенное состояние с ускорением календаря и документацией по rollback.

Что включает:
- фикс `06994da` `fix(onec): delete stale slots missing from schedule batch`
- фикс `426a19a` `perf(calendar): preload applications for slot events`
- документацию `a44f8c6` `docs(deploy): add rollback tag guide`

Когда использовать:
- если нужно вернуться к текущему проверенному состоянию
- если ускорение календаря уже принято и нужно сохранить его как отдельную контрольную точку

## Текущие важные коммиты

- `06994da` `fix(onec): delete stale slots missing from schedule batch`
- `426a19a` `perf(calendar): preload applications for slot events`
- `a44f8c6` `docs(deploy): add rollback tag guide`

## Самый безопасный способ отката

Предпочтительный вариант: не делать `reset`, а делать `revert` нужного коммита и пушить в `main`.

Почему:
- история не ломается
- автодеплой продолжает работать штатно
- проще понять, что именно было отменено

### Откатить только ускорение календаря

Если нужно убрать только performance-фикс календаря и оставить фикс 1С:

```bash
git checkout main
git pull origin main
git revert 426a19a
git push origin main
```

Результат:
- фикс календаря будет отменён
- фикс 1С останется
- документация по rollback останется

### Откатить фикс 1С и ускорение календаря

Если нужно вернуться к состоянию до обоих фиксов:

```bash
git checkout main
git pull origin main
git revert 426a19a
git revert 06994da
git push origin main
```

Результат:
- откатится ускорение календаря
- откатится фикс 1С

## Откат именно к тегу

Использовать этот путь стоит только если нужен именно возврат к конкретной точке состояния.

Важно:
- не делать `git reset --hard` в рабочей ветке `main`
- безопаснее создать отдельную ветку от тега и либо быстро проверить код, либо сделать revert/cherry-pick осознанно

### Посмотреть код из тега

```bash
git checkout prod-stable-post-onec-2026-03-17
```

или

```bash
git checkout prod-stable-2026-03-11
```

Это переключит репозиторий в detached HEAD.

Для текущего проверенного состояния:

```bash
git checkout tested-calendar-perf-2026-03-17
```

### Создать ветку от тега

```bash
git checkout -b codex/rollback-check prod-stable-post-onec-2026-03-17
```

или

```bash
git checkout -b codex/rollback-check prod-stable-2026-03-11
```

Это удобно, если нужно:
- быстро проверить состояние кода
- временно задеплоить тег через отдельную ветку
- сравнить изменения перед откатом

Для текущего проверенного состояния:

```bash
git checkout -b codex/rollback-check tested-calendar-perf-2026-03-17
```

## Как понять, какой тег нужен

Если проблема в новом ускорении календаря:
- использовать `prod-stable-post-onec-2026-03-17`

Если проблема глубже и нужно убрать также фикс 1С:
- использовать `prod-stable-2026-03-11`

Если нужно вернуться к текущему проверенному состоянию после последующих экспериментов:
- использовать `tested-calendar-perf-2026-03-17`

## Полезные команды

Посмотреть, куда указывает тег:

```bash
git show prod-stable-post-onec-2026-03-17 --stat --oneline
```

```bash
git show prod-stable-2026-03-11 --stat --oneline
```

```bash
git show tested-calendar-perf-2026-03-17 --stat --oneline
```

Посмотреть последние коммиты:

```bash
git log --oneline --decorate -10
```

Сравнить текущее состояние с тегом:

```bash
git diff prod-stable-post-onec-2026-03-17..main
```

```bash
git diff prod-stable-2026-03-11..main
```

```bash
git diff tested-calendar-perf-2026-03-17..main
```

## Практическое правило

Если нужно быстро и безопасно:
- для отката одной конкретной правки использовать `git revert`
- теги использовать как контрольные точки, чтобы понимать, к какому состоянию возвращаемся
