create table clinics_doctors
(
    id        serial
        primary key,
    clinic_id integer not null
        references clinics,
    doctor_id integer not null
        references doctors
);

alter table clinics_doctors
    owner to clinic_management_user;

create index clinics_doctors_clinic_id_idx
    on clinics_doctors (clinic_id);

create index clinics_doctors_doctor_id_idx
    on clinics_doctors (doctor_id);

