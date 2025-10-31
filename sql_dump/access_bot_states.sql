create table access_bot_states
(
    user_id bigserial
        primary key,
    chat_id bigint,
    state   varchar(255)
);

alter table access_bot_states
    owner to clinic_management_user;

create index ix_access_bot_states_chat_id
    on access_bot_states (chat_id);

INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (5280396379, 5280396379, 'BotState:city');
INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (388921507, 388921507, 'BotState:city');
INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (328465249, 328465249, 'BotState:phone');
INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (1638757039, 1638757039, 'BotState:phone');
INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (1990296856, 1990296856, 'BotState:city');
INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (128405576, 128405576, 'BotState:phone');
INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (434679616, 434679616, 'BotState:phone');
INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (265063, 265063, 'BotState:phone');
INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (1231431368, 1231431368, 'BotState:phone');
INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (5592681987, 5592681987, 'BotState:phone');
INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (1273819140, 1273819140, 'BotState:phone');
INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (511245408, 511245408, 'BotState:city');
INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (1294372932, 1294372932, 'BotState:phone');
INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (1336689903, 1336689903, 'BotState:phone');
INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (6670207216, 6670207216, 'BotState:age');
INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (1786426518, 1786426518, 'BotState:phone');
INSERT INTO public.access_bot_states (user_id, chat_id, state) VALUES (410437133, 410437133, 'BotState:age');
