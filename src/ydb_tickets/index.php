<?php

/**
 * Это «руки» агента: три кнопки, которыми модель заводит и читает заявки.
 * Сама модель в базу ходить не умеет — она умеет только «нажать кнопку»,
 * а нажатие через MCP-шлюз превращается в вызов этой функции.
 *
 *   create-ticket    завести заявку + первую запись в историю  -> tickets + messages
 *   list-my-tickets  показать заявки одного пользователя       -> tickets
 *   append-message   дописать реплику в историю заявки         -> messages
 *
 * ОТКУДА ПРИХОДИТ ВЫЗОВ (три источника — отсюда «диспетчер» ниже)
 * ---------------------------------------------------------------
 *   1) Прямой вызов:  yc serverless function invoke ... --data '{"action":"create-ticket",...}'
 *      В событии есть поле action — работы никакой.
 *   2) HTTP через API Gateway: {"httpMethod":"POST","body":"<JSON-строка>"}
 *      Настоящие данные лежат СТРОКОЙ внутри body — надо распаковать.
 *   3) MCP Hub (агент): аргументы приходят голыми, без обёртки и БЕЗ поля action.
 *      Поэтому действие приходится угадывать по набору ключей — detectByKeys().
 *
 * ОТКУДА БЕРУТСЯ НАСТРОЙКИ
 * ------------------------
 *   YDB_ENDPOINT, YDB_DATABASE — секреты из Lockbox, подключаются при деплое.
 *   YC_IAM_TOKEN — только для ЛОКАЛЬНОГО запуска. В облаке его нет, и тогда
 *                  функция берёт токен сама из metadata-сервиса (см. ydbConnect).
 */

declare(strict_types=1);

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

use YdbPlatform\Ydb\Auth\Implement\AccessTokenAuthentication;
use YdbPlatform\Ydb\Auth\Implement\MetadataAuthentication;
use YdbPlatform\Ydb\Session;
use YdbPlatform\Ydb\Ydb;

/**
 * @throws JsonException
 */
function handler($event): array
{
    $isHttp = is_array($event) && isset($event['httpMethod']);

    try {
        $args = normalizeEvent($event);
        $action = $args['action'] ?? detectByKeys($args);

        error_log(sprintf(
            'TOOL_CALL action=%s source=%s keys=%s',
            $action ?? 'unknown',
            $isHttp ? 'http' : (isset($args['action']) ? 'invoke' : 'mcp'),
            implode(',', array_keys($args))
        ));

        $result = match ($action) {
            'create-ticket' => createTicket($args),
            'list-my-tickets' => listMyTickets($args),
            'append-message' => appendMessage($args),
            default => ['error' => 'unknown action', 'got_keys' => array_keys($args)],
        };
    } catch (Throwable $e) {
        // Ошибку не прячем: агент увидит текст и сможет сказать о ней человеку.
        error_log('TOOL_ERROR ' . get_class($e) . ': ' . $e->getMessage());
        $result = ['error' => $e->getMessage()];
    }

    // API Gateway ждёт ответ в своей обёртке, остальные — просто JSON.
    if ($isHttp) {
        return [
            'statusCode' => isset($result['error']) ? 400 : 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
    }

    return $result;
}

// ===========================================================================
// ДИСПЕТЧЕР: приводим три формы события к одному массиву аргументов
// ===========================================================================

/**
 * Достаёт аргументы из события любого из трёх видов.
 * @throws JsonException
 */
function normalizeEvent($event): array
{
    if (!is_array($event)) {
        return [];
    }

    // Вариант 2: HTTP через API Gateway — данные строкой внутри body.
    if (isset($event['body']) && is_string($event['body'])) {
        $body = $event['body'];
        if (($event['isBase64Encoded'] ?? false) === true) {
            $body = base64_decode($body, true) ?: '';
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    // Варианты 1 и 3: аргументы лежат прямо в событии.
    return $event;
}

/**
 * Угадывает действие по набору ключей — нужно для вызовов из MCP,
 * где поля action нет вообще.
 *
 * Ключи не пересекаются, поэтому порядок проверок однозначен:
 *   есть ticket_id -> дописываем реплику в существующую заявку;
 *   есть text -> заводим новую заявку;
 *   есть user_id -> показываем заявки пользователя.
 */
function detectByKeys(array $args): ?string
{
    if (isset($args['ticket_id'])) {
        return 'append-message';
    }
    if (isset($args['text'])) {
        return 'create-ticket';
    }
    if (isset($args['user_id'])) {
        return 'list-my-tickets';
    }
    return null;
}

/**
 * Заводит заявку и сразу кладёт исходное обращение первой записью в историю.
 * Обе записи — в ОДНОЙ транзакции: либо есть и заявка, и история, либо ничего.
 * @throws Exception
 */
function createTicket(array $args): array
{
    $userId = requireArg($args, 'user_id');
    $text = maskPii(strval($args['text'] ?? ''));
    $category = normalizeCategory($args['category'] ?? 'other');

    $ticketId = uuid4();
    $messageId = uuid4();
    $now = utcNow();

    $yql = <<<'YQL'
        DECLARE $id AS Utf8;
        DECLARE $user_id AS Utf8;
        DECLARE $category AS Utf8;
        DECLARE $status AS Utf8;
        DECLARE $text AS Utf8;
        DECLARE $now AS Timestamp;
        DECLARE $message_id AS Utf8;
        DECLARE $role AS Utf8;

        UPSERT INTO tickets (id, user_id, category, status, text, created_at, updated_at)
        VALUES ($id, $user_id, $category, $status, $text, $now, $now);

        UPSERT INTO messages (id, ticket_id, role, text, created_at)
        VALUES ($message_id, $id, $role, $text, $now);
        YQL;

    ydbTransaction(static function (Session $session) use ($yql, $ticketId, $userId, $category, $text, $now, $messageId) {
        return $session->prepare($yql)->execute([
            '$id' => $ticketId,
            '$user_id' => $userId,
            '$category' => $category,
            '$status' => 'open',
            '$text' => $text,
            '$now' => $now,
            '$message_id' => $messageId,
            '$role' => 'user',
        ]);
    });

    error_log('TICKET_CREATED id=' . $ticketId . ' user=' . $userId);

    return ['ticket_id' => $ticketId, 'created_at' => $now];
}

/**
 * Возвращает заявки пользователя, свежие сверху.
 * Читаем через вторичный индекс (VIEW tickets_by_user) — иначе YDB
 * пришлось бы просматривать таблицу целиком.
 * @throws Exception
 */
function listMyTickets(array $args): array
{
    $userId = requireArg($args, 'user_id');
    $limit = (int)($args['limit'] ?? 20);
    $limit = max(1, min($limit, 50));

    $yql = <<<'YQL'
        DECLARE $user_id AS Utf8;
        DECLARE $limit AS Uint64;

        SELECT id, status, category, text, CAST(created_at AS Utf8) AS created_at
        FROM tickets VIEW tickets_by_user
        WHERE user_id = $user_id
        ORDER BY created_at DESC
        LIMIT $limit;
        YQL;

    $result = ydbTransaction(static function (Session $session) use ($yql, $userId, $limit) {
        return $session->prepare($yql)->execute([
            '$user_id' => $userId,
            '$limit' => $limit,
        ]);
    });

    $rows = $result->rows();
    error_log('TICKETS_LISTED user=' . $userId . ' count=' . count($rows));

    // array_values — чтобы в JSON ушёл массив [...], а не объект {"0":...}.
    return array_values($rows);
}

/**
 * Дописывает реплику в историю заявки и продлевает её updated_at,
 * чтобы авто-эскалация (шаг 06) не считала живую переписку «зависшей».
 * @throws Exception
 */
function appendMessage(array $args): array
{
    $ticketId = requireArg($args, 'ticket_id');
    $text = maskPii(strval($args['text'] ?? ''));
    $role = ($args['role'] ?? 'agent') === 'user' ? 'user' : 'agent';

    $messageId = uuid4();
    $now = utcNow();

    $yql = <<<'YQL'
        DECLARE $id AS Utf8;
        DECLARE $ticket_id AS Utf8;
        DECLARE $role AS Utf8;
        DECLARE $text AS Utf8;
        DECLARE $model AS Utf8;
        DECLARE $tokens_in AS Uint64;
        DECLARE $tokens_out AS Uint64;
        DECLARE $latency_ms AS Uint32;
        DECLARE $now AS Timestamp;

        UPSERT INTO messages (id, ticket_id, role, text, model, tokens_in, tokens_out, latency_ms, created_at)
        VALUES ($id, $ticket_id, $role, $text, $model, $tokens_in, $tokens_out, $latency_ms, $now);

        UPDATE tickets SET updated_at = $now WHERE id = $ticket_id;
        YQL;

    ydbTransaction(static function (Session $session) use ($yql, $messageId, $ticketId, $role, $text, $args, $now) {
        return $session->prepare($yql)->execute([
            '$id' => $messageId,
            '$ticket_id' => $ticketId,
            '$role' => $role,
            '$text' => $text,
            '$model' => strval($args['model'] ?? ''),
            '$tokens_in' => (int)($args['tokens_in'] ?? 0),
            '$tokens_out' => (int)($args['tokens_out'] ?? 0),
            '$latency_ms' => (int)($args['latency_ms'] ?? 0),
            '$now' => $now,
        ]);
    });

    error_log('MESSAGE_APPENDED ticket=' . $ticketId . ' role=' . $role);

    return ['message_id' => $messageId, 'ok' => true];
}

/**
 * Выполняет запрос в транзакции с повторами.
 *
 * Соединение создаётся заново на каждый вызов функции — это нормально:
 * функция живёт секунды, а держать пул между запусками облако не даёт.
 * @throws \YdbPlatform\Ydb\Exception
 */
function ydbTransaction(Closure $work)
{
    $ydb = ydbConnect();

    // Второй аргумент — «идемпотентно ли»: наши UPSERT'ы по сгенерированному
    // UUID можно безопасно повторить, поэтому true (SDK сам сделает ретраи).
    return $ydb->table()->retryTransaction($work, true);
}

/**
 * @throws \YdbPlatform\Ydb\Exception
 */
function ydbConnect(): Ydb
{
    $endpoint = strval(getenv('YDB_ENDPOINT') ?: '');
    $database = strval(getenv('YDB_DATABASE') ?: '');

    if ($endpoint === '' || $database === '') {
        throw new RuntimeException('YDB_ENDPOINT / YDB_DATABASE не заданы — проверьте --secret при деплое');
    }

    // В .env адрес лежит как grpcs://host:2135, а SDK ждёт голое host:2135.
    $host = preg_replace('#^grpcs?://#i', '', $endpoint);
    $host = explode('/', strval($host))[0];

    $config = [
        'database' => $database,
        'endpoint' => $host,
        'discovery' => false, // функция живёт секунды: лишний round-trip не нужен
        'iam_config' => [
            // Единственный каталог, куда в Cloud Functions можно писать.
            'temp_dir' => sys_get_temp_dir() . '/ydb',
        ],
    ];

    $localToken = getenv('YC_IAM_TOKEN');
    $config['credentials'] = $localToken
        ? new AccessTokenAuthentication(strval($localToken))  // запуск с ноутбука
        : new MetadataAuthentication();                       // запуск в облаке

    return new Ydb($config);
}

function requireArg(array $args, string $name): string
{
    $value = $args[$name] ?? null;
    if ($value === null || $value === '') {
        throw new InvalidArgumentException('Не передан обязательный аргумент: ' . $name);
    }

    // MCP может прислать chat id числом — в базе это Utf8, поэтому приводим к строке.
    return is_scalar($value) ? strval($value) : '';
}

/** Категория из фиксированного списка: мусор в базу не пускаем. */
function normalizeCategory($value): string
{
    $allowed = ['bug', 'docs', 'feature', 'access', 'other'];
    $value = is_string($value) ? strtolower(trim($value)) : 'other';

    return in_array($value, $allowed, true) ? $value : 'other';
}

/**
 * Текущее время UTC в формате, который понимает YDB-тип Timestamp.
 * @throws Exception
 */
function utcNow(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
}

/**
 * Заглушка персональных данных ПЕРЕД записью в базу.
 * Базовая версия; на шаге 08 её расширяем и дополняем guardrail'ом.
 * Маскируем именно ТЕКСТ обращения — user_id (chat id) остаётся открытым,
 * иначе агент не сможет ответить и найти прошлые заявки.
 */
function maskPii(string $text): string
{
    // Номер карты: 13–19 цифр, возможно с пробелами или дефисами.
    $text = preg_replace('/\b(?:\d[ -]?){13,19}\b/', '****-****-****-****', $text) ?? $text;

    // Телефон: +7 999 123-45-67 и подобное — оставляем две последние цифры.
    $text = preg_replace_callback(
        '/(?:\+?\d[\s()-]?){10,14}\d/',
        static fn(array $m): string => '+7 (***) ***-**-' . substr(preg_replace('/\D/', '', $m[0]) ?: '', -2),
        $text
    ) ?? $text;

    // Почта.
    $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[email]', $text) ?? $text;

    return $text;
}

/**
 * UUID v4 без расширений — просто 16 случайных байт с двумя служебными битами.
 * @throws \Random\RandomException
 */
function uuid4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}
