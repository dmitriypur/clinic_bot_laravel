create table webhooks
(
    id         uuid          not null
        primary key,
    user_id    bigint        not null
        references users,
    link       varchar(2048) not null,
    secret     varchar(4096) not null,
    created_at timestamp,
    updated_at timestamp
);

alter table webhooks
    owner to clinic_management_user;

create index ix_webhooks_user_id
    on webhooks (user_id);

create index user_id_idx
    on webhooks (user_id);

INSERT INTO public.webhooks (id, user_id, link, secret, created_at, updated_at) VALUES ('ba5a1bf2-e7bf-43f8-8a4f-94944453ddbe', 7, 'https://h.albato.ru/wh/38/1lfttc8/l_MBGIsBHC1o14JuPnHeiSZnrqanULx1PGUJRp_n_tk/', 'aG2sP9jK1oL3mN7bR5cV0xY4zQ6wI8uF2eD1hC9bA7vX3yZ5pR8qT0oN2mL4kP6jI8hG1fE3dC5bA7vX9yZ1pQ3rT5oN7mL9kP1jI3hG5fE7dC9bA2vX4yZ6pR8qT0oN2mL4kP6jI8hG1fE3dC5bA7vX9yZ1pQ3rT5oN7mL9kP1jI3hG5fE7dC9bA2vX4yZ6pR8qT0oN2mL4kP6jI8hG1fE3dC5bA7vX9yZ1pQ3rT5oN7mL9kP1jI3hG5fE7dC9bA2vX4yZ6p', '2025-06-11 06:37:51.094888', '2025-06-11 06:37:51.094888');
