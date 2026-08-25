#!/usr/bin/env bash
# Настройка reverse-proxy к Telegram Bot API на внешнем сервере (nginx в docker).
#
# ЧТО ДЕЛАЕТ (в одну команду, с бэкапом и проверкой):
#   1. берёт ключ доступа к прокси из Lockbox (секрет tg-proxy-key) — в терминале не печатает;
#   2. на сервере делает копию nginx-конфига сайта (путь — TG_PROXY_NGINX_CONF из .env);
#   3. вставляет location /tg/ → https://api.telegram.org/ (только с заголовком X-Proxy-Key,
#      без access-логов, чтобы токен бота не оседал в файлах);
#   4. nginx -t; если синтаксис ок — мягкий reload (сайт не останавливается),
#      если нет — автоматически возвращает бэкап;
#   5. проверяет снаружи: без ключа → 403, с ключом → getMe отвечает.
#
# ЗАПУСК:  bash scripts/setup-tg-proxy.sh
#          адрес сервера, пути и имя контейнера берутся из .env (см. .env.example, блок TG_PROXY_*)
# ОТКАТ:   на сервере вернуть *.bak-* файл на место и docker exec <контейнер> nginx -s reload
set -euo pipefail

# --- читаем .env из корня проекта (только строки вида KEY=value, комментарии пропускаем) ---
ENV_FILE="$(cd "$(dirname "$0")/.." && pwd)/.env"
[ -f "$ENV_FILE" ] || { echo "нет $ENV_FILE — скопируй .env.example в .env и заполни блок TG_PROXY_*"; exit 1; }
while IFS='=' read -r k v; do
  [[ "$k" =~ ^[A-Z_][A-Z0-9_]*$ ]] || continue
  export "$k=$v"
done < <(grep -E '^[A-Z_][A-Z0-9_]*=' "$ENV_FILE")

SERVER="${TG_PROXY_SERVER:?в .env нет TG_PROXY_SERVER (user@host)}"
SSH_KEY="${TG_PROXY_SSH_KEY:?в .env нет TG_PROXY_SSH_KEY}"
SSH_KEY="${SSH_KEY/#\~/$HOME}"                        # разворачиваем ~ в домашнюю папку
CONF="${TG_PROXY_NGINX_CONF:?в .env нет TG_PROXY_NGINX_CONF}"
CONTAINER="${TG_PROXY_NGINX_CONTAINER:?в .env нет TG_PROXY_NGINX_CONTAINER}"
CONF_IN_CONTAINER="${TG_PROXY_NGINX_CONF_IN_CONTAINER:?в .env нет TG_PROXY_NGINX_CONF_IN_CONTAINER}"
API_BASE="${TELEGRAM_API_BASE:?в .env нет TELEGRAM_API_BASE (https://<домен>/tg)}"
API_BASE="${API_BASE%/}"
YC="${YC_BIN:-$(command -v yc || echo "$HOME/yandex-cloud/bin/yc")}"

KEY=$("$YC" lockbox payload get --name tg-proxy-key --format json | jq -r '.entries[] | select(.key=="key") | .text_value')
[ -n "$KEY" ] || { echo "не удалось получить ключ из Lockbox"; exit 1; }

ssh -o BatchMode=yes -o ConnectTimeout=10 -i "$SSH_KEY" "$SERVER" "KEY='$KEY' CONF='$CONF' CONTAINER='$CONTAINER' CONF_IN_CONTAINER='$CONF_IN_CONTAINER' bash -s" <<'REMOTE'
set -e
BAK="$CONF.bak-$(date +%Y%m%d-%H%M%S)"
cp -a "$CONF" "$BAK"
echo "бэкап: $BAK"

if grep -q 'location .*/tg/' "$CONF"; then
  echo "location /tg/ уже есть — пропускаю вставку"
else
  BLOCK="
    # --- Telegram Bot API proxy для help-desk-бота: Yandex Cloud не достаёт до Telegram ---
    location ^~ /tg/ {                                      # ^~: префикс главнее regex-location из сниппета
        if (\$http_x_proxy_key != \"$KEY\") { return 403; }   # чужих не пускаем
        access_log off;                                     # токен бота в URL — в логи не пишем
        proxy_pass https://api.telegram.org/;
        proxy_ssl_server_name on;
        proxy_set_header Host api.telegram.org;
        proxy_connect_timeout 5s;
        proxy_read_timeout 30s;
    }
"
  # вставляем блок перед строкой include (внутри server { ... })
  awk -v block="$BLOCK" '/include \/etc\/nginx\/snippets\/backend-api-locations.conf;/ && !done { print block; done=1 } { print }' "$CONF" > "$CONF.new"
  # ВАЖНО: файл примонтирован в контейнер как ОТДЕЛЬНЫЙ файл. mv или sed -i сменили бы inode,
  # и контейнер продолжил бы видеть старую версию. Поэтому пишем содержимое в тот же inode.
  cat "$CONF.new" > "$CONF" && rm -f "$CONF.new"
  echo "блок вставлен"
fi

# Синхронизируем файл ВНУТРИ контейнера (тот inode, который реально читает nginx):
# на случай, если inode на хосте уже когда-то сменился.
docker exec -i "$CONTAINER" sh -c "cat > '$CONF_IN_CONTAINER'" < "$CONF"

if docker exec "$CONTAINER" nginx -t 2>&1 | grep -q 'test is successful'; then
  docker exec "$CONTAINER" nginx -s reload
  echo "nginx: синтаксис ок, перезагружен без остановки"
else
  echo "nginx -t НЕ ПРОШЁЛ — возвращаю бэкап"
  cp -a "$BAK" "$CONF"
  docker exec "$CONTAINER" nginx -t 2>&1 | tail -2
  exit 1
fi
REMOTE

echo
echo "=== проверка снаружи ==="
printf 'без ключа (ожидаю 403): '
curl -s -o /dev/null -w '%{http_code}\n' --max-time 15 "$API_BASE/bot0:x/getMe"
printf 'с ключом, getMe: '
TOKEN=$("$YC" lockbox payload get --name telegram-bot-token --format json | jq -r '.entries[] | select(.key=="token") | .text_value')
curl -s --max-time 15 -H "X-Proxy-Key: $KEY" "$API_BASE/bot${TOKEN}/getMe" | jq -c '{ok, username: .result.username}'
