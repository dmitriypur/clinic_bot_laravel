create table users
(
    id            bigserial
        primary key,
    username      varchar(100) not null,
    hash_password varchar(255) not null,
    status        integer      not null,
    role          varchar(25)  not null,
    clinic_id     integer
        references clinics,
    created_at    timestamp,
    updated_at    timestamp
);

alter table users
    owner to clinic_management_user;

create index users_role_idx
    on users (role);

create index users_status_idx
    on users (status);

create unique index users_username_idx
    on users (username);

create index users_clinic_id_idx
    on users (clinic_id);

INSERT INTO public.users (id, username, hash_password, status, role, clinic_id, created_at, updated_at) VALUES (1, 'admin', '$2b$12$EQ4M.hRMqqalV00GL51duejQTVjsuArcutVzDmBLVOyMUQgMq7qFy', 1, 'admin', null, '2024-05-16 00:00:21.000000', '2024-05-16 00:00:22.000000');
INSERT INTO public.users (id, username, hash_password, status, role, clinic_id, created_at, updated_at) VALUES (2, 'Мама, Я вижу!', '$2b$12$eCUwUe5/W3AUOiXw1L7tvezqEds9j9Shd6AiIfrQEPgciwxQQpcli', 1, 'partner', 2, '2024-05-30 13:43:40.179898', '2024-05-30 15:32:23.648306');
INSERT INTO public.users (id, username, hash_password, status, role, clinic_id, created_at, updated_at) VALUES (5, 'Имидж Оптика', '$2b$12$HcxmlGgS6CZRhwrYblrTKeK6uekEFrKHPMZZIVzLA4eboo/fV2Cmy', 1, 'partner', 6, '2024-11-20 14:10:54.347382', '2024-11-20 14:10:54.347382');
INSERT INTO public.users (id, username, hash_password, status, role, clinic_id, created_at, updated_at) VALUES (9, 'ОмегаОптик', '$2b$12$BInWaEB.4NpWL6xpS//PZe29JKBJguIS1/txTKwqCMksnLTkBBHwe', 1, 'partner', 8, '2024-12-06 15:58:27.679665', '2024-12-06 15:58:27.679665');
INSERT INTO public.users (id, username, hash_password, status, role, clinic_id, created_at, updated_at) VALUES (7, 'Доктор Линз', '$2b$12$dCJDtRiHYbVdRQBdoVptSuhI0z9Xy6iosRYhhvTDTcfvKP0htYKRi', 1, 'partner', 12, '2024-11-27 11:59:04.999520', '2024-12-06 16:00:36.079804');
INSERT INTO public.users (id, username, hash_password, status, role, clinic_id, created_at, updated_at) VALUES (3, 'ДЗЗ', '$2b$12$JlFEaU1EM2pLlLAqjr.NuOfpNc/yjtv2BUPH9VqZlexB6IzhV4pG2', 1, 'partner', 1, '2024-06-04 13:32:52.103681', '2025-06-03 12:54:40.328960');
INSERT INTO public.users (id, username, hash_password, status, role, clinic_id, created_at, updated_at) VALUES (8, 'Уралочки', '$2b$12$hYyc.flQPN/Urh3qPqPoQ.owG4VwiLaPcX6LQrTTFQIVm2Fqor3uW', 1, 'partner', 5, '2024-12-02 16:34:10.149378', '2025-06-03 12:53:08.974896');
INSERT INTO public.users (id, username, hash_password, status, role, clinic_id, created_at, updated_at) VALUES (6, 'Прозрение', '$2b$12$wyW705DxYscwXO93EMKlHeP4nCQXidZvxCUbsf5aDEA2YiKHT1s9u', 1, 'partner', 10, '2024-11-27 10:46:32.754216', '2025-06-04 18:04:18.111538');
INSERT INTO public.users (id, username, hash_password, status, role, clinic_id, created_at, updated_at) VALUES (10, 'Линзочки', '$2b$12$eT2KEuSVW0mjl8gZqih66.NIxTxnzg.X9Kjur2lK3ysJoJn3YXS1e', 1, 'partner', 17, '2025-06-02 20:02:54.890052', '2025-06-05 14:16:53.945661');
INSERT INTO public.users (id, username, hash_password, status, role, clinic_id, created_at, updated_at) VALUES (11, 'Спектр Оптика', '$2b$12$V0hIXBSB9AlGwXkaKRAhSu86YzhEYyzPA9rp/eF2f08mLtJF///aC', 1, 'partner', 9, '2025-06-02 20:03:47.266696', '2025-06-05 14:17:07.221196');
INSERT INTO public.users (id, username, hash_password, status, role, clinic_id, created_at, updated_at) VALUES (12, 'Оптик-Взгляд', '$2b$12$xVo2o7xfEKBBZKkpFP7S2OkolXJyshGuc2eQIxpE0OIzw0RpMIXb6', 1, 'partner', 15, '2025-06-02 20:04:49.933354', '2025-06-05 14:17:18.792039');
INSERT INTO public.users (id, username, hash_password, status, role, clinic_id, created_at, updated_at) VALUES (13, 'Инвизер', '$2b$12$KdCpeLtK1jOrQw64UCrbC.V6lG900.iPFCle.LP5WGemjHX1ET7fG', 1, 'partner', 14, '2025-06-02 20:05:32.232308', '2025-06-05 14:17:28.985896');
INSERT INTO public.users (id, username, hash_password, status, role, clinic_id, created_at, updated_at) VALUES (14, 'КД Линза', '$2b$12$NVnvc.tn2fL/o3XdA7H/aegSES8CrYJtE1iMEtLNGhS.NaiS2QIoe', 1, 'partner', 16, '2025-06-02 20:06:27.251352', '2025-06-05 14:17:39.420048');
INSERT INTO public.users (id, username, hash_password, status, role, clinic_id, created_at, updated_at) VALUES (15, 'НФЗДЗ', '$2b$12$1VNJKtCbIx4zM41zvkm2hueGboAWm4qcxq0mbajlag78nMcJNzXBy', 1, 'admin_read_only', null, '2025-07-14 22:36:29.018980', '2025-07-14 22:36:29.018980');
