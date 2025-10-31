create table reviews
(
    id         serial
        primary key,
    text       varchar(4000),
    rating     smallint not null,
    user_id    bigint   not null,
    doctor_id  integer  not null
        references doctors,
    status     integer  not null,
    created_at timestamp,
    updated_at timestamp
);

alter table reviews
    owner to clinic_management_user;

create index reviews_user_id_idx
    on reviews (user_id);

create index reviews_doctor_id_idx
    on reviews (doctor_id);

