# Инструкция: перенос филиалов и врачей с теста на прод

Документ для кейса, когда:
- на `app.fondzrenie.ru` (тест) уже вручную заполнены филиалы/врачи;
- на `adminzrenie.ru` (прод) клиники есть, а филиалов/врачей еще нет;
- нужно перенести данные по конкретной клинике (`clinic_id`).

## 1. Что переносим

Только нужные таблицы:
- `doctors`
- `branches`
- `clinic_doctor`
- `branch_doctor`
- `integration_endpoints` (если интеграция по филиалам уже настроена)

## 2. Бэкап прода (обязательно)

На прод-сервере:

```bash
mysqldump --no-tablespaces --single-transaction --quick -u dmitriypur -p medical_center > /tmp/prod-backup-$(date +%F-%H%M).sql
ls -lh /tmp/prod-backup-*.sql | tail -n 1
```

## 3. Экспорт с теста

На тест-сервере (пример для `clinic_id=2`):

```bash
CLINIC_ID=2
DB=dev_bot
DB_USER=dmitriypur

mysqldump --no-tablespaces -u $DB_USER -p --no-create-info --skip-triggers $DB branches \
  --where="clinic_id=${CLINIC_ID}" > /tmp/branches.sql

mysqldump --no-tablespaces -u $DB_USER -p --no-create-info --skip-triggers $DB doctors \
  --where="id IN (SELECT doctor_id FROM clinic_doctor WHERE clinic_id=${CLINIC_ID})" > /tmp/doctors.sql

mysqldump --no-tablespaces -u $DB_USER -p --no-create-info --skip-triggers $DB clinic_doctor \
  --where="clinic_id=${CLINIC_ID}" > /tmp/clinic_doctor.sql

mysqldump --no-tablespaces -u $DB_USER -p --no-create-info --skip-triggers $DB branch_doctor \
  --where="branch_id IN (SELECT id FROM branches WHERE clinic_id=${CLINIC_ID})" > /tmp/branch_doctor.sql

mysqldump --no-tablespaces -u $DB_USER -p --no-create-info --skip-triggers $DB integration_endpoints \
  --where="branch_id IN (SELECT id FROM branches WHERE clinic_id=${CLINIC_ID})" > /tmp/integration_endpoints.sql
```

Проверка, что файлы создались:

```bash
ls -lh /tmp/{doctors,branches,clinic_doctor,branch_doctor,integration_endpoints}.sql
```

## 4. Перенос файлов на прод

С теста на прод любым удобным способом (`scp`/`rsync`).

Пример:

```bash
scp /tmp/doctors.sql /tmp/branches.sql /tmp/clinic_doctor.sql /tmp/branch_doctor.sql /tmp/integration_endpoints.sql root@adminzrenie.ru:/tmp/
```

## 5. Импорт на прод

На прод-сервере:

```bash
mysql -u dmitriypur -p medical_center < /tmp/doctors.sql
mysql -u dmitriypur -p medical_center < /tmp/branches.sql
mysql -u dmitriypur -p medical_center < /tmp/integration_endpoints.sql
mysql -u dmitriypur -p medical_center < /tmp/clinic_doctor.sql
mysql -u dmitriypur -p medical_center < /tmp/branch_doctor.sql
```

Порядок важен: сначала сущности (`doctors`, `branches`), потом связи.

## 6. Проверка после импорта

На проде в каталоге проекта:

```bash
php artisan tinker --execute="dump(['branches'=>\App\Models\Branch::where('clinic_id',2)->count(),'doctors'=>\App\Models\Doctor::whereHas('clinics',fn($q)=>$q->where('clinic_id',2))->count()]);"
```

Проверка внешних ID филиалов:

```bash
php artisan tinker --execute="dump(\App\Models\Branch::where('clinic_id',2)->get(['id','name','external_id'])->toArray());"
```

## 7. Если нужна откатка

На проде:

```bash
mysql -u dmitriypur -p medical_center < /tmp/prod-backup-YYYY-MM-DD-HHMM.sql
```

Подставить фактическое имя бэкапа из `/tmp`.

## 8. Важные замечания

- Инструкция рассчитана на сценарий: на проде еще нет филиалов/врачей для этой клиники.
- Если на проде уже есть данные, нужен `upsert`-сценарий (по `external_id`) вместо прямого дампа.
- Для интеграции 1С у филиалов должен быть заполнен `external_id`.
