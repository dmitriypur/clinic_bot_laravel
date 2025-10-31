create table access_bot_data
(
    user_id bigserial
        primary key,
    chat_id bigint,
    data    json
);

alter table access_bot_data
    owner to clinic_management_user;

create index ix_access_bot_data_chat_id
    on access_bot_data (chat_id);

INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (511245408, 511245408, '{"is_child": true}');
INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (1294372932, 1294372932, '{"is_child": true, "city_id": 7, "clinic_id": 8}');
INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (1336689903, 1336689903, '{"is_child": false, "city_id": 1, "clinic_id": 1}');
INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (328465249, 328465249, '{"is_child": true, "city_id": 2, "clinic_id": 2}');
INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (1638757039, 1638757039, '{"is_child": true, "city_id": 2, "clinic_id": 2}');
INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (1990296856, 1990296856, '{"is_child": true}');
INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (128405576, 128405576, '{"is_child": true, "city_id": 2, "clinic_id": 2}');
INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (434679616, 434679616, '{"is_child": false, "city_id": 1, "clinic_id": 1}');
INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (410437133, 410437133, '{"is_child": false, "city_id": 1, "clinic_id": 1}');
INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (1231431368, 1231431368, '{"is_child": true, "city_id": 2, "clinic_id": 2}');
INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (5592681987, 5592681987, '{"is_child": false, "city_id": 2, "clinic_id": 2}');
INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (6670207216, 6670207216, '{"is_child": false, "city_id": 2, "clinic_id": 2}');
INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (1786426518, 1786426518, '{"is_child": true, "city_id": 7, "clinic_id": 8}');
INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (5280396379, 5280396379, '{"is_child": true}');
INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (388921507, 388921507, '{"is_child": true}');
INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (265063, 265063, '{"is_child": true, "city_id": 5, "clinic_id": 11}');
INSERT INTO public.access_bot_data (user_id, chat_id, data) VALUES (1273819140, 1273819140, '{"is_child": true, "city_id": 6, "clinic_id": 7}');
