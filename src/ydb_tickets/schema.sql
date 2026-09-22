-- Схема YDB для Help Desk-агента (шаг 05).
-- Две таблицы: tickets — заявки, messages — история реплик по заявке (1 : N).
-- Применяется YDB CLI:  ydb -p hexlet sql -f src/ydb_tickets/schema.sql
-- (профиль hexlet = endpoint + database; IAM-токен — в переменной окружения IAM_TOKEN).

CREATE TABLE tickets (
    id          Utf8,               -- UUID заявки
    user_id     Utf8,               -- chat id в Telegram (в почтовом варианте — email)
    category    Utf8,               -- bug | docs | feature | access
    status      Utf8,               -- open | answered | escalated
    text        Utf8,               -- текст обращения ПОСЛЕ PII-маскирования
    created_at  Timestamp,
    updated_at  Timestamp,
    INDEX tickets_by_user GLOBAL ON (user_id),   -- «мои заявки» по user_id
    PRIMARY KEY (id)
);

CREATE TABLE messages (
    id          Utf8,               -- UUID реплики
    ticket_id   Utf8,               -- -> tickets.id
    role        Utf8,               -- user | agent
    text        Utf8,               -- текст ПОСЛЕ PII-маскирования
    model       Utf8,               -- какая модель отвечала
    tokens_in   Uint64,
    tokens_out  Uint64,
    latency_ms  Uint32,
    created_at  Timestamp,
    PRIMARY KEY (ticket_id, id)
);
