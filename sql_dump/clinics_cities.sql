create table clinics_cities
(
    id        serial
        primary key,
    clinic_id integer not null
        references clinics,
    city_id   integer not null
        references cities
);

alter table clinics_cities
    owner to clinic_management_user;

create index clinics_cities_clinic_id_idx
    on clinics_cities (clinic_id);

create index clinics_cities_city_id_idx
    on clinics_cities (city_id);

INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (1, 1, 1);
INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (2, 2, 2);
INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (3, 5, 3);
INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (4, 6, 4);
INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (6, 8, 7);
INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (7, 9, 8);
INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (8, 10, 9);
INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (9, 11, 5);
INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (10, 12, 10);
INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (11, 13, 11);
INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (12, 14, 12);
INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (13, 15, 13);
INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (14, 16, 14);
INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (15, 17, 15);
INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (16, 17, 16);
INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (17, 8, 17);
INSERT INTO public.clinics_cities (id, clinic_id, city_id) VALUES (18, 8, 18);
