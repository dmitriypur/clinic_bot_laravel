create table doctors
(
    id                 serial
        primary key,
    last_name          varchar(255) not null,
    first_name         varchar(255) not null,
    second_name        varchar(255),
    experience         integer      not null,
    age                integer      not null,
    photo_src          json,
    diploma_src        json,
    status             integer      not null,
    age_admission_from integer      not null,
    age_admission_to   integer      not null,
    sum_ratings        integer,
    count_ratings      integer,
    uuid               uuid,
    review_link        varchar(255),
    created_at         timestamp,
    updated_at         timestamp
);

alter table doctors
    owner to clinic_management_user;

create index ix_doctors_uuid
    on doctors (uuid);

create index doctors_age_admission_idx
    on doctors (age_admission_from, age_admission_to);

create index doctors_status_idx
    on doctors (status);

create unique index doctors_uuid_idx
    on doctors (uuid);

