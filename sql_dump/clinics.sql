create table clinics
(
    id         serial
        primary key,
    name       varchar(500) not null,
    status     integer      not null,
    created_at timestamp,
    updated_at timestamp
);

alter table clinics
    owner to clinic_management_user;

create index clinics_name_idx
    on clinics (name);

create index clinics_status_idx
    on clinics (status);

INSERT INTO public.clinics (id, name, status, created_at, updated_at) VALUES (1, 'Дом здорового зрения', 1, '2024-05-16 07:51:50.313273', '2024-05-16 07:51:50.313273');
INSERT INTO public.clinics (id, name, status, created_at, updated_at) VALUES (2, 'Мама, Я вижу!', 1, '2024-05-30 08:19:35.991791', '2024-05-30 08:19:35.991791');
INSERT INTO public.clinics (id, name, status, created_at, updated_at) VALUES (5, 'УралОчки', 1, '2024-09-16 14:01:23.380643', '2024-09-16 14:01:35.813080');
INSERT INTO public.clinics (id, name, status, created_at, updated_at) VALUES (6, 'Имидж оптика', 1, '2024-09-16 14:02:02.153754', '2024-09-16 14:02:13.048394');
INSERT INTO public.clinics (id, name, status, created_at, updated_at) VALUES (9, '«Спектр-оптика» центр контроля миопии', 1, '2024-09-16 14:07:55.272311', '2024-09-16 14:08:54.328026');
INSERT INTO public.clinics (id, name, status, created_at, updated_at) VALUES (11, 'Центр Здорового Зрения', 1, '2024-09-16 14:09:53.037262', '2024-09-16 14:09:53.037262');
INSERT INTO public.clinics (id, name, status, created_at, updated_at) VALUES (10, 'Прозрение', 1, '2024-09-16 14:09:11.930846', '2024-11-27 11:54:50.825905');
INSERT INTO public.clinics (id, name, status, created_at, updated_at) VALUES (12, 'Доктор Линз', 1, '2024-11-27 11:55:05.225197', '2024-11-27 12:16:06.117775');
INSERT INTO public.clinics (id, name, status, created_at, updated_at) VALUES (13, 'IQ-Оптика', 1, '2025-03-18 13:08:09.684940', '2025-03-18 13:08:09.684940');
INSERT INTO public.clinics (id, name, status, created_at, updated_at) VALUES (14, 'Инвизер', 1, '2025-06-02 13:14:43.723887', '2025-06-02 13:14:43.723887');
INSERT INTO public.clinics (id, name, status, created_at, updated_at) VALUES (15, 'Оптик-Взгляд', 1, '2025-06-02 13:16:31.725484', '2025-06-02 13:16:31.725484');
INSERT INTO public.clinics (id, name, status, created_at, updated_at) VALUES (16, 'КД Линза', 1, '2025-06-02 13:51:31.773743', '2025-06-02 13:51:31.773743');
INSERT INTO public.clinics (id, name, status, created_at, updated_at) VALUES (17, 'Линзочки', 1, '2025-06-02 13:52:33.027279', '2025-06-02 13:52:33.027279');
INSERT INTO public.clinics (id, name, status, created_at, updated_at) VALUES (8, 'Центр коррекция зрения «Омега Оптик»', 1, '2024-09-16 14:07:32.701668', '2025-06-02 13:53:54.777501');
