create table cities
(
    id         serial
        primary key,
    name       varchar(255) not null,
    status     integer      not null,
    created_at timestamp,
    updated_at timestamp
);

alter table cities
    owner to clinic_management_user;

create index cities_name_idx
    on cities (name);

create index cities_status_idx
    on cities (status);

INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (1, 'Киров', 1, '2024-05-16 07:51:27.857108', '2024-05-16 07:51:27.857108');
INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (2, 'Москва', 1, '2024-05-30 08:19:13.389846', '2024-05-30 08:19:13.389846');
INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (3, 'Екатеринбург', 1, '2024-09-16 14:00:37.039336', '2024-09-16 14:00:37.039336');
INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (4, 'Курск', 1, '2024-09-16 14:01:54.773649', '2024-09-16 14:01:54.773649');
INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (5, 'Тульская область', 1, '2024-09-16 14:02:34.033779', '2024-09-16 14:03:12.842157');
INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (8, 'Барнаул', 1, '2024-09-16 14:04:53.776401', '2024-09-16 14:04:53.776401');
INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (9, 'Тольятти', 1, '2024-09-16 14:04:59.630167', '2024-09-16 14:04:59.630167');
INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (10, 'Самара', 1, '2024-11-27 11:53:19.828920', '2024-11-27 11:53:19.828920');
INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (11, 'Тула', 1, '2025-03-18 13:07:48.671729', '2025-03-18 13:07:48.671729');
INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (12, 'Симферополь', 1, '2025-06-02 13:13:36.295545', '2025-06-02 13:13:36.295545');
INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (13, 'Магнитогорск', 1, '2025-06-02 13:15:59.504971', '2025-06-02 13:15:59.504971');
INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (14, 'Калининград', 1, '2025-06-02 13:49:44.715921', '2025-06-02 13:49:44.715921');
INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (15, 'Южно-Сахалинск', 1, '2025-06-02 13:52:10.933709', '2025-06-02 13:52:10.933709');
INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (16, 'Невельск', 1, '2025-06-02 13:52:17.358983', '2025-06-02 13:52:17.358983');
INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (17, 'Туапсе', 1, '2025-06-02 13:52:59.636696', '2025-06-02 13:52:59.636696');
INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (18, 'Горячий Ключ', 1, '2025-06-02 13:53:19.471656', '2025-06-02 13:53:19.471656');
INSERT INTO public.cities (id, name, status, created_at, updated_at) VALUES (7, 'Краснодар', 1, '2024-09-16 14:04:40.855691', '2025-06-02 19:57:47.661226');
