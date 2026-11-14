# Деплой adminzrenie.ru

Пуш в `main` запускает [Deploy test then production](.github/workflows/deploy.yml). GitHub Actions собирает Vite и Composer-зависимости по lock-файлам, запускает PHPUnit и упаковывает релиз с SHA-256. Сервер разворачивает архив на `test.adminzrenie.ru`, проверяет публичный сайт и выполняет `booking:verify-staging`. Runner дополнительно проверяет HTTPS, опубликованный SHA, `/up` и JSON от `/api/v1/cities` с активным городом. Только после успешных шагов workflow отправляет команду продвижения того же архива и SHA-256 на прод. Перед переключением релиза сервер сверяет SHA с актуальным `main`. Выкладки сериализуются в Actions и на сервере.

## Пути и доступ

| | Тест | Прод |
|---|---|---|
| URL | `https://test.adminzrenie.ru` | `https://adminzrenie.ru` |
| Активный релиз | `/var/www/dev-test/current` | `/var/www/medical-center` |
| Каталог релизов | `/var/www/dev-test/releases` | `/var/www/medical-center-releases/releases` |
| Постоянные `.env` и `storage` | `/var/www/dev-test/shared` | `/var/www/medical-center-releases/legacy` |
| MySQL | `adminzrenie_test` | `medical_center` |

В GitHub Environment `production` находятся секреты `DEPLOY_SSH_KEY` и `DEPLOY_KNOWN_HOSTS`. SSH-пользователь `adminzrenie-deploy` принимает только команды выкладки и проверку соединения. Серверные копии `scripts/deploy-release.sh` и `scripts/verify-release.sh` принадлежат root и установлены как `/usr/local/sbin/adminzrenie-deploy` и `/usr/local/lib/adminzrenie/verify-release.sh`. При изменении этих скриптов администратор обновляет их по проверенному SSH до первого запуска, который требует новой версии.

Тест использует отдельный PHP-FPM-пул, ключ приложения, хранилище, сеансы и кеш. Сидер `StagingSeeder` создаёт статусы заявок. Справочник городов и клиники «Ангелы зрения» с филиалами и врачами, роли и разрешения хранятся в тестовой базе. Справочник перенесён с прода отдельной операцией без рабочих записей, учётных записей и настроек интеграций. На тесте отключены 1С, мониторинг записи и фоновые задачи. Почтовый транспорт хранит сообщения только в памяти. Ключи Telegram, CRM и боевых API на тесте отсутствуют. Старая копия `clinic_bot_dev` и её конфигурация Supervisor находятся в закрытом архиве `/var/www/dev-test-archive`. Её MySQL-база `dev_bot` сохранена.

Для `booking:verify-staging` файл `/var/www/dev-test/shared/elevenlabs-booking-authorization` содержит полную строку авторизации тестового клиента `elevenlabs-test`. Права файла `0640`, владелец `root:adminzrenie-test`. Клиенту нужны четыре права API, а филиал на Преображенской, 38, должен быть разрешён для него и в `BOOKING_WRITE_BRANCH_IDS` теста. Поддерживайте в этом филиале свободную локальную смену для взрослого в пределах ближайших 30 дней. Проверка подтверждает запись через публичный API, проверяет конкурентный отказ, статус и повтор, затем удаляет заявки и операции только своего прогона. При ошибке проверки сервер откатывает переключение тестового релиза, а workflow останавливается до продвижения.

Ручной повтор на сервере под root:

```bash
cd /var/www/dev-test/current
runuser -u adminzrenie-test -- /usr/bin/php8.3 artisan booking:verify-staging /var/www/dev-test/shared/elevenlabs-booking-authorization
```

## Откат кода

Тег `rollback-before-staged-deploy-2026-11-13` фиксирует `bd4ec4677bcf0ca921b43dcae8ef41394258c997`. Исходный каталог этого прода сохранён как `/var/www/medical-center-releases/legacy`. При ошибке серверной проверки после переключения скрипт возвращает прежний путь к коду и подаёт сигнал на перезапуск очереди прода.

Для ручного отката после первой успешной выкладки дождитесь завершения активного запуска. На сервере под root возьмите тот же lock и атомарно замените ссылку. Выберите сохранённый каталог релиза или `legacy` для возврата к тегу.

```bash
sudo flock /var/www/medical-center-releases/deploy.lock bash
test -L /var/www/medical-center || exit 1
release=/var/www/medical-center-releases/legacy
test -f "$release/artisan" || exit 1
ln -s "$release" /var/www/medical-center-releases/rollback-next
mv -Tf /var/www/medical-center-releases/rollback-next /var/www/medical-center
cd /var/www/medical-center
runuser -u www-data -- /usr/bin/php8.3 artisan queue:restart
curl -fsS https://adminzrenie.ru/up
curl -fsS https://adminzrenie.ru/api/v1/cities | jq -e '.data | length > 0'
exit
```

Следующий пуш в `main` снова запустит выкладку. Для постоянного возврата исправьте код в `main` через `git revert`, сохранив новый workflow.

## Восстановление базы

Перед миграциями сервер создаёт сжатый MySQL-дамп и SHA-256 в `/var/backups/adminzrenie`. Для каждого сайта хранятся пять последних дампов. Выкладка и откат ссылки оставляют применённые миграции в базе.

Для восстановления БД выберите дамп по времени и SHA, проверьте `sha256sum -c <файл>.sha256` из каталога дампов и `gzip -t <файл>`. Проверьте импорт в отдельную закрытую базу восстановления и сравните схему и контрольные данные. Восстановление боевой БД требует отдельного согласования и остановки записей сайта, планировщика и очереди на время переключения. Оно может потерять изменения после времени дампа.
