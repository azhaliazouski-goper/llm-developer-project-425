### Hexlet tests and linter status:
[![Actions Status](https://github.com/azhaliazouski-goper/llm-developer-project-425/actions/workflows/hexlet-check.yml/badge.svg)](https://github.com/azhaliazouski-goper/llm-developer-project-425/actions)

# Help Desk-агент (проект 425)

Telegram-агент службы поддержки на Yandex Cloud: принимает обращение в чате бота, ищет ответ в
корпоративной базе знаний (RAG); если готового ответа нет — создаёт тикет и ведёт историю
диалога в YDB через собственный MCP-инструмент. Раз в день эскалирует зависшие тикеты оператору.

Канал — **Telegram** (урок допускает его как альтернативу почте; см. приложение к шагу 4 курса).

## Как попробовать

- Бот: **@hexlet_ynadex_bot**. Сначала нажмите `/start` (бот не может написать первым),
  затем задайте вопрос.
- Ответ приходит с задержкой **до 60 секунд**: поллер опрашивает Telegram раз в минуту
  (pull-архитектура).

## Стек

- **YandexGPT** (Responses API), **YDB Serverless**, **MCP Hub**, **Cloud Functions**,
  **Yandex Workflows**, **Lockbox**.
- Язык решения — **PHP** (рантайм `php82`). Python нужен только для CLI `yandex-ai-studio`
  (загрузка базы знаний, шаг RAG).

## Архитектура (шаг 4)

```text
Timer (раз в минуту) ──► CF telegram-poller ──► getUpdates (забрать новое)
                                │                    │
                                │◄── Responses API ──┘  (YandexGPT, IAM из metadata)
                                ▼
                          sendMessage (GET) ──► ответ в чат
                                ▼
                          offset = last+1  (идемпотентность: Telegram сам
                                            «забывает» подтверждённое)
```

## База данных YDB (шаг 5)

Serverless-база `help-desk-db`, две таблицы (схема — `src/ydb_tickets/schema.sql`):

| Таблица | Что хранит | Ключ | Индексы |
|---|---|---|---|
| `tickets` | заявки: `user_id` (chat id), `category`, `status` (`open` → `answered` → `escalated`), `text` после PII-маскирования, `created_at`, `updated_at` | `id` | `tickets_by_user` по `user_id` — «мои заявки» |
| `messages` | история реплик по заявке: `role` (`user`/`agent`), `text`, `model`, `tokens_in/out`, `latency_ms`, `created_at` | `(ticket_id, id)` | — |

Таблицы создаются официальной утилитой **YDB CLI** (`ydb`) — один бинарник, без кода и
зависимостей. Ставится в `~/ydb/bin` без прав администратора:

```bash
curl -sSL https://install.ydb.tech/cli | bash -s -- -n     # -n: не трогать ~/.bashrc
export PATH="$HOME/ydb/bin:$PATH"

# профиль с адресом и путём базы (значения — из .env), чтобы не повторять их в каждой команде
ydb config profile create hexlet --endpoint "$YDB_ENDPOINT" --database "$YDB_DATABASE"

# токен — временный пропуск на ~12 часов; CLI читает его из IAM_TOKEN
export IAM_TOKEN=$(yc iam create-token)

# создать таблицы и проверить
ydb -p hexlet sql -f src/ydb_tickets/schema.sql
ydb -p hexlet scheme ls -l
ydb -p hexlet scheme describe tickets
```

`scheme describe` должен показать 7 колонок и индекс `tickets_by_user` у `tickets`,
9 колонок и составной ключ `(ticket_id, id)` у `messages`.

## Секреты (Lockbox)

Секреты **не хранятся в коде и в git**. Функции получают их при деплое по имени секрета
(`--secret ...,name=<secret>,key=...`). В `.env` (файл в `.gitignore`) — только ID секретов.

| Секрет | Что хранит | key | Где используется |
|---|---|---|---|
| `telegram-bot-token` | токен бота от @BotFather | `token` | CF `telegram-poller` → `TELEGRAM_BOT_TOKEN` |
| `tg-proxy-key` | ключ доступа к прокси Bot API | `key` | CF `telegram-poller` → `TELEGRAM_PROXY_KEY` (см. «Известные особенности») |
| `ydb-endpoint` | адрес YDB (`grpcs://…:2135`) | `value` | CF `ydb-tickets` → `YDB_ENDPOINT` |
| `ydb-database` | путь базы (`/ru-central1/…`) | `value` | CF `ydb-tickets` → `YDB_DATABASE` |

## Сервисный аккаунт

`ai-studio-sa` прикрепляется к Cloud Functions; авторизация к AI Studio и MCP-шлюзу идёт по его
IAM-токену из metadata-сервиса (вручную не обновляется).

| Роль | Зачем |
|---|---|
| `functions.functionInvoker` | вызывать функции |
| `serverless.mcpGateways.invoker` | вызывать MCP-инструменты |
| `lockbox.payloadViewer` | читать секреты из Lockbox |
| `ai.languageModels.user` | обращаться к модели (Responses API) |
| `ydb.editor` | читать/писать в YDB |

Для workflow-шага авто-эскалации дополнительно: `ai.assistants.editor`,
`serverless.workflows.executor`, `serverless.workflows.viewer`.

## Переменные окружения

Шаблон — в `.env.example`, реальные значения — в `.env` (в git не попадает):

- `YDB_ENDPOINT`, `YDB_DATABASE` — подключение к базе (для локальных скриптов);
- `*_SECRET_ID` — ID секретов в Lockbox (для справки);
- `AGENT_ID` — id агента `help-desk` в AI Studio;
- `MCP_GATEWAY_URL` — SSE-адрес шлюза `ydb-tickets-mcp`;
- `BOT_USERNAME` — username бота;
- `TELEGRAM_API_BASE` — адрес Bot API для функции (у нас — прокси, см. ниже);
- `OPERATOR_CHAT_ID` — chat id оператора, туда уходит дайджест эскалации (шаг 6).

## Деплой поллера

```bash
yc serverless function version create \
  --function-name telegram-poller \
  --runtime php82 \
  --entrypoint telegram_poller.handler \
  --memory 256m \
  --execution-timeout 60s \
  --source-path src/telegram_poller.php \
  --service-account-id <SA_ID> \
  --environment YC_FOLDER_ID=<folder-id>,MCP_GATEWAY_URL=https://<mcp-gateway-host>/sse,TELEGRAM_API_BASE=https://api.gpt-chat.by/tg \
  --secret environment-variable=TELEGRAM_BOT_TOKEN,name=telegram-bot-token,key=token \
  --secret environment-variable=TELEGRAM_PROXY_KEY,name=tg-proxy-key,key=key

yc serverless trigger create timer \
  --name telegram-poller-trigger \
  --cron-expression "0/1 * * * ? *" \
  --invoke-function-name telegram-poller \
  --invoke-function-tag '$latest' \
  --invoke-function-service-account-id <SA_ID>
```

Локальный запуск (для отладки): `export YC_IAM_TOKEN=$(yc iam create-token)` — поллер возьмёт
токен из окружения вместо metadata-сервиса.

## Известные особенности: почему Bot API ходит через прокси

**Факт (замерено 24–25.08.2026):** из Yandex Cloud соединения к `api.telegram.org` не проходят —
ни из Cloud Functions, ни из API Gateway; пакеты молча отбрасываются на этапе connect. Из тех же
функций Wikipedia/Google/GitHub открываются, а Facebook/Instagram — нет: это сетевая фильтрация
на российских магистралях, а не ошибка кода. Приложение к уроку («блокируется только POST»)
описывает более ранний, мягкий режим фильтрации.

**Решение — транспортное, не архитектурное.** Архитектура pull сохранена полностью: таймер →
`getUpdates` → модель → `sendMessage` → `offset`. Изменилась только настройка окружения:
`TELEGRAM_API_BASE` указывает на reverse-proxy `https://api.gpt-chat.by/tg/` (nginx на внешнем
сервере), который пересылает запросы в `https://api.telegram.org/` и возвращает ответ. Код о
прокси не знает: без переменной он ходит в Telegram напрямую.

Защита прокси:
- пропускает только запросы с заголовком `X-Proxy-Key` (ключ — секрет `tg-proxy-key` в Lockbox);
- `access_log off` — токен бота (он в URL) не попадает в логи сервера;
- шифрование сквозное: функция → прокси и прокси → Telegram по HTTPS с проверкой сертификата.

Настройка прокси автоматизирована: `scripts/setup-tg-proxy.sh` (бэкап конфига, вставка `location`,
`nginx -t`, мягкий reload, авто-откат при ошибке). Адрес сервера, пути и имя контейнера скрипт берёт
из `.env` (блок `TG_PROXY_*`, шаблон в `.env.example`). Откат — вернуть `*.bak-*` и reload.

Отладка сети: `yc serverless function invoke telegram-poller -d '{"debug":true}'` — покажет
исходящий IP функции и доступность Telegram, не трогая сообщения.
