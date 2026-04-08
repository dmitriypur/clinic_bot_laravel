# API Contract For Booking Widgets

Этот документ фиксирует публичный API-контракт backend-приложения
`adminzrenie-laravel`, который используется:

- текущим WebApp внутри этого проекта;
- внешним приложением `zrenie.clinic-7`;
- внешним виджетом [bookingApi.js](/Applications/MAMP/htdocs/zrenie.clinic-7/resources/js/services/bookingApi.js).

Цель документа: не допустить случайных поломок при рефакторинге.

## Правило совместимости

Для перечисленных ниже эндпоинтов нельзя менять без отдельной миграции
клиента и без обновления contract tests:

- HTTP method и path;
- названия query/body параметров;
- envelope ответа (`data`, `errors`, `message`);
- обязательные поля в элементах коллекций;
- формат `422` validation errors;
- семантику `is_available`, `is_occupied`, `is_past`, `onec_slot_id`,
  `available_slots`, `first_available_time`.

## Критичный внешний клиент

Внешний виджет использует следующие вызовы:

- `GET /api/v1/cities`
- `GET /api/v1/cities/{city}/clinics`
- `GET /api/v1/clinics/{clinic}/branches`
- `GET /api/v1/cities/{city}/doctors`
- `GET /api/v1/cities/{city}/doctors-by-date`
- `GET /api/v1/clinics/{clinic}/doctors`
- `GET /api/v1/doctors/{doctor}/slots`
- `GET /api/v1/booking/calendar-availability`
- `POST /api/v1/applications/check-slot`
- `POST /api/v1/applications`

Источник: [bookingApi.js](/Applications/MAMP/htdocs/zrenie.clinic-7/resources/js/services/bookingApi.js#L32)

## Frozen Contracts

### `GET /api/v1/cities`

Response shape:

```json
{
  "data": [
    {
      "id": 1,
      "name": "Simferopol",
      "status": 1
    }
  ]
}
```

### `GET /api/v1/cities/{city}/clinics`

Response shape:

```json
{
  "data": [
    {
      "id": 1,
      "name": "Clinic",
      "branches": [
        {
          "id": 10,
          "name": "Branch"
        }
      ]
    }
  ]
}
```

### `GET /api/v1/clinics/{clinic}/branches?city_id=`

Response shape:

```json
{
  "data": [
    {
      "id": 10,
      "name": "Branch",
      "address": "Address",
      "phone": "+79990000000"
    }
  ]
}
```

### `GET /api/v1/cities/{city}/doctors`
### `GET /api/v1/clinics/{clinic}/doctors?branch_id=&birth_date=`

Response shape:

```json
{
  "data": [
    {
      "id": 100,
      "name": "Doctor Name",
      "experience": 10,
      "age": 40,
      "photo_src": null,
      "diploma_src": null,
      "status": 1,
      "age_admission_from": 0,
      "age_admission_to": 99,
      "uuid": "uuid",
      "review_link": null,
      "external_id": "external-doctor-id"
    }
  ]
}
```

Notes:

- `uuid` и `external_id` критичны для маппинга врача на стороне внешнего сайта.
- поле `name` используется как основное отображаемое имя.
- возрастная фильтрация поддерживает открытые границы:
  - `age_admission_from = null` означает отсутствие нижней границы;
  - `age_admission_to = null` означает отсутствие верхней границы.

### `GET /api/v1/cities/{city}/doctors-by-date?date=&birth_date=&clinic_id=&branch_id=`

Response shape:

```json
{
  "data": [
    {
      "id": "100-10-2025-01-02",
      "date": "2025-01-02",
      "doctor_id": 100,
      "branch_id": 10,
      "clinic_id": 1,
      "name": "Doctor Name",
      "experience": 10,
      "age": 40,
      "photo_src": null,
      "diploma_src": null,
      "status": 1,
      "age_admission_from": 0,
      "age_admission_to": 99,
      "uuid": "uuid",
      "review_link": null,
      "external_id": "external-doctor-id",
      "speciality": null,
      "branch_name": "Branch",
      "branch_address": "Address",
      "clinic_name": "Clinic",
      "available_slots": 3,
      "first_available_time": "09:00"
    }
  ]
}
```

Notes:

- endpoint агрегирует доступных врачей на выбранную дату по городу;
- каждый элемент коллекции соответствует связке `doctor + branch + date`;
- в ответ попадают только врачи, у которых есть хотя бы один доступный слот на выбранную дату;
- `birth_date` применяет ту же возрастную фильтрацию, что и обычные doctor collections, включая открытые границы;
- `clinic_id` и `branch_id` являются опциональными сужающими фильтрами для будущего сценария выбора по дате во внешнем виджете.

### `GET /api/v1/doctors/{doctor}/slots?date=&clinic_id=&branch_id=`

Response shape:

```json
{
  "data": [
    {
      "id": "local:1",
      "shift_id": 1,
      "cabinet_id": 1,
      "branch_id": 10,
      "clinic_id": 1,
      "branch_name": "Branch",
      "clinic_name": "Clinic",
      "cabinet_name": "Cabinet",
      "time": "09:00",
      "datetime": "2025-01-02 09:00",
      "duration": 30,
      "is_past": false,
      "is_occupied": false,
      "is_available": true,
      "onec_slot_id": null
    }
  ]
}
```

Notes:

- внешний виджет рассчитывает доступность из `is_available`, `is_occupied`,
  `is_past`, а также fallback-логики по `status`, если такие поля появятся;
- `datetime`, `time`, `branch_id`, `clinic_id`, `cabinet_id`, `onec_slot_id`
  используются в выборе слота и отправке заявки.

### `GET /api/v1/booking/calendar-availability`

Response shape:

```json
{
  "data": [
    {
      "date": "2025-01-02",
      "total_slots": 4,
      "available_slots": 3,
      "first_available_time": "09:00"
    }
  ]
}
```

Notes:

- внешний виджет подсвечивает только даты, где `available_slots > 0`.

### `POST /api/v1/applications/check-slot`

Request shape:

```json
{
  "clinic_id": 1,
  "branch_id": 10,
  "doctor_id": 100,
  "onec_slot_id": "slot-id"
}
```

Success response:

```json
{
  "status": "ok"
}
```

Validation error shape:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "onec_slot_id": [
      "..."
    ]
  }
}
```

### `POST /api/v1/applications`

Minimal request shape used by widgets:

```json
{
  "city_id": 1,
  "clinic_id": 1,
  "branch_id": 10,
  "doctor_id": 100,
  "cabinet_id": 1,
  "appointment_datetime": "2025-01-02 09:00",
  "onec_slot_id": null,
  "full_name": "Patient Name",
  "full_name_parent": null,
  "birth_date": "2010-01-01",
  "phone": "79990000000",
  "promo_code": null,
  "comment": null,
  "appointment_source": "site"
}
```

Success response keeps resource envelope and these fields:

- status code: `201 Created`
- `id`
- `city_id`
- `clinic_id`
- `branch_id`
- `doctor_id`
- `cabinet_id`
- `full_name_parent`
- `full_name`
- `birth_date`
- `appointment_datetime`
- `phone`
- `promo_code`
- `tg_user_id`
- `tg_chat_id`
- `send_to_1c`
- `integration_type`
- `integration_status`
- `external_appointment_id`
- `integration_payload`
- `created_at`
- `updated_at`

Validation error shape:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "city_id": [
      "..."
    ],
    "full_name": [
      "..."
    ],
    "phone": [
      "..."
    ]
  }
}
```

## Safe Refactoring Rule

Если меняется только внутренняя реализация, но контракт выше остается тем же,
такой рефакторинг допустим только после прогона contract tests.

Если требуется изменить любой пункт этого документа, сначала обновляется:

1. этот документ;
2. contract tests;
3. внешний клиент `zrenie.clinic-7`.
