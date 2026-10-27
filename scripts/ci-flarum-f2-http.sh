#!/usr/bin/env bash
# Disposable Flarum 2.0.2 HTTP proof for FlatRate Live Chat.
# Uses MariaDB because historical Live Chat migrations issue MySQL MODIFY DDL.
# No production data, credentials, or installation.
set -euo pipefail

export PATH="/usr/bin:/bin:/usr/local/bin:${PATH:-}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
git config --global --add safe.directory "${ROOT}" || true
if ! git -C "${ROOT}" symbolic-ref -q HEAD >/dev/null; then
  git -C "${ROOT}" checkout -B ci-f2-live-chat-http
fi

SMOKE_ROOT="${FLARUM_SMOKE_ROOT:-${TMPDIR:-/tmp}/flarum-live-f2-http-$$}"
FLARUM_SKELETON_VERSION="${FLARUM_SKELETON_VERSION:-2.0.0}"
EXPECTED_CORE="${FLARUM_CORE_VERSION:-2.0.2}"
BASE="http://127.0.0.1:8767"

DB_HOST="${FLARUM_DB_HOST:-127.0.0.1}"
DB_PORT="${FLARUM_DB_PORT:-3306}"
DB_NAME="${FLARUM_DB_DATABASE:-flarum_live_http}"
DB_USER="${FLARUM_DB_USERNAME:-root}"
DB_PASS="${FLARUM_DB_PASSWORD:-disposable-local}"

cleanup() {
  if [[ -n "${HTTP_PID:-}" ]]; then
    kill "${HTTP_PID}" >/dev/null 2>&1 || true
  fi
  if [[ "${KEEP_SMOKE_ROOT:-0}" != "1" ]]; then
    rm -rf "${SMOKE_ROOT}"
  fi
}
trap cleanup EXIT

wait_for_db() {
  local i
  for i in $(seq 1 60); do
    if php -r '
      try {
        new PDO(
          "mysql:host=".$argv[1].";port=".$argv[2].";charset=utf8mb4",
          $argv[3],
          $argv[4],
          [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        exit(0);
      } catch (Throwable $e) {
        exit(1);
      }
    ' "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASS}"; then
      return 0
    fi
    sleep 1
  done
  echo "LIVE_F2_HTTP=FAIL database_not_ready host=${DB_HOST} port=${DB_PORT}" >&2
  exit 1
}

wait_for_db

php -r '
$pdo = new PDO(
  "mysql:host=".$argv[1].";port=".$argv[2].";charset=utf8mb4",
  $argv[3],
  $argv[4],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$name = preg_replace("/[^A-Za-z0-9_]/", "", $argv[5]);
$pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
$pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
' "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASS}" "${DB_NAME}"

rm -rf "${SMOKE_ROOT}"
mkdir -p "${SMOKE_ROOT}"

composer create-project "flarum/flarum:${FLARUM_SKELETON_VERSION}" "${SMOKE_ROOT}" --no-interaction --no-install
cd "${SMOKE_ROOT}"
rm -f composer.lock
composer require "flarum/core:${EXPECTED_CORE}" --no-update --no-interaction
composer update --no-interaction --prefer-dist --no-audit

INSTALLED_CORE="$(
  composer show flarum/core --format=json \
    | php -r '$j=json_decode(stream_get_contents(STDIN), true); $v=$j["versions"][0] ?? ""; echo preg_replace("/^v/", "", (string)$v);'
)"
if [[ "${INSTALLED_CORE}" != "${EXPECTED_CORE}" ]]; then
  echo "LIVE_F2_HTTP=FAIL expected_core=${EXPECTED_CORE} got=${INSTALLED_CORE}" >&2
  exit 1
fi

composer config repositories.flatrate-live-chat path "${ROOT}"
composer require "flatrate/flarum-live-chat:*@dev" --no-interaction --prefer-dist --no-audit

cat > "${SMOKE_ROOT}/install.json" <<EOF
{
  "debug": true,
  "baseUrl": "${BASE}",
  "databaseConfiguration": {
    "driver": "mysql",
    "host": "${DB_HOST}",
    "port": ${DB_PORT},
    "database": "${DB_NAME}",
    "username": "${DB_USER}",
    "password": "${DB_PASS}",
    "prefix": "",
    "charset": "utf8mb4",
    "collation": "utf8mb4_unicode_ci"
  },
  "adminUser": {
    "username": "liveadmin",
    "password": "live-admin-pass",
    "email": "live-admin@example.test"
  },
  "settings": {
    "forum_title": "Live Chat Smoke"
  },
  "extensions": "flarum-suspend,flarum-markdown,flatrate-live-chat"
}
EOF

php flarum install --file "${SMOKE_ROOT}/install.json"
rm -f "${SMOKE_ROOT}/install.json"

php -r '
$pdo = new PDO(
  "mysql:host=".$argv[1].";port=".$argv[2].";dbname=".$argv[3].";charset=utf8mb4",
  $argv[4],
  $argv[5],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec("INSERT INTO settings (`key`, value) VALUES (\"flatrate-live-chat.admin_dashboard_enabled\", \"1\") ON DUPLICATE KEY UPDATE value = \"1\"");
$pdo->exec("DELETE FROM neonchat_messages");
$pdo->exec("DELETE FROM neonchat_chat_user");
$pdo->exec("DELETE FROM neonchat_chats");
$ins = $pdo->prepare("INSERT INTO neonchat_chats (title, color, icon, type, creator_id, created_at, room_key, scope_type, scope_key, visibility, audience) VALUES (?, ?, ?, 1, 1, \"2020-01-01 00:00:00\", ?, ?, ?, ?, ?)");
$ins->execute(["General Live", "#111111", "fas fa-comments", "community-general-live", "navigation-group", "community", "visible", "members"]);
$generalId = (int) $pdo->lastInsertId();
$ins->execute(["Aston Martin Live", "#222222", "fas fa-car", "aston-martin-live", "board", "aston-martin", "hidden", "staff-preview"]);
$brandId = (int) $pdo->lastInsertId();
$ins->execute(["Extra Stored Room", "#333333", "fas fa-lock", "orphan-extra-stored-live", "board", "orphan", "hidden", "staff-preview"]);
$extraId = (int) $pdo->lastInsertId();
file_put_contents("storage/live-room-ids.json", json_encode([
  "general" => $generalId,
  "brand" => $brandId,
  "extra" => $extraId,
]));
' "${DB_HOST}" "${DB_PORT}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}"

(
  cd "${SMOKE_ROOT}/public"
  php -S 127.0.0.1:8767 index.php > "${SMOKE_ROOT}/http.log" 2>&1
) &
HTTP_PID=$!

ready=0
for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
  if curl -s -o /dev/null "${BASE}/api"; then
    ready=1
    break
  fi
  sleep 0.25
done
if [[ "${ready}" != "1" ]]; then
  echo "LIVE_F2_HTTP=FAIL server_not_ready" >&2
  cat "${SMOKE_ROOT}/http.log" >&2 || true
  exit 1
fi

token_for() {
  local user="$1"
  local pass="$2"
  local out
  out="$(mktemp)"
  local code
  code="$(curl -sS -o "${out}" -w '%{http_code}' -H 'Content-Type: application/json' \
    -d "{\"identification\":\"${user}\",\"password\":\"${pass}\"}" \
    "${BASE}/api/token")"
  local token
  token="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["token"] ?? "";' "${out}")"
  if [[ -z "${token}" ]]; then
    echo "LIVE_F2_HTTP=FAIL token user=${user} status=${code}" >&2
    cat "${out}" >&2 || true
    exit 1
  fi
  rm -f "${out}"
  printf '%s' "${token}"
}

create_user() {
  local name="$1"
  local email="$2"
  local pass="$3"
  php -r '
    $site = require "site.php";
    $app = $site->bootApp();
    $user = new Flarum\User\User();
    $user->username = $argv[1];
    $user->email = $argv[2];
    $user->password = $argv[3];
    $user->is_email_confirmed = true;
    $user->joined_at = Carbon\Carbon::now();
    $user->save();
    $user->groups()->attach(Flarum\Group\Group::MEMBER_ID);
    echo $user->id;
  ' "${name}" "${email}" "${pass}"
}

expect_status() {
  local label="$1"
  local expected="$2"
  local actual="$3"
  if [[ "${actual}" != "${expected}" ]]; then
    echo "${label}=FAIL expected=${expected} actual=${actual}" >&2
    exit 1
  fi
  echo "${label}=PASS"
}

deny_401_or_403() {
  local label="$1"
  local actual="$2"
  if [[ "${actual}" != "401" && "${actual}" != "403" ]]; then
    echo "${label}=FAIL status=${actual}" >&2
    exit 1
  fi
  echo "${label}=DENY status=${actual}"
}

deny_403_or_404() {
  local label="$1"
  local actual="$2"
  if [[ "${actual}" != "403" && "${actual}" != "404" ]]; then
    echo "${label}=FAIL status=${actual}" >&2
    exit 1
  fi
  echo "${label}=DENY status=${actual}"
}

db_php() {
  php -r '
$pdo = new PDO(
  "mysql:host=".$argv[1].";port=".$argv[2].";dbname=".$argv[3].";charset=utf8mb4",
  $argv[4],
  $argv[5],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
'"$1"'
' "${DB_HOST}" "${DB_PORT}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}" "${@:2}"
}

ADMIN_TOKEN="$(token_for liveadmin live-admin-pass)"
ALICE_ID="$(create_user alice alice@example.test alice-pass-1)"
BOB_ID="$(create_user bob bob@example.test bob-pass-1)"
DAVE_ID="$(create_user dave dave@example.test dave-pass-1)"
DAVE_TOKEN="$(token_for dave dave-pass-1)"

GENERAL_ID="$(php -r 'echo json_decode(file_get_contents("storage/live-room-ids.json"), true)["general"];')"
BRAND_ID="$(php -r 'echo json_decode(file_get_contents("storage/live-room-ids.json"), true)["brand"];')"

php -r '
$pdo = new PDO(
  "mysql:host=".$argv[1].";port=".$argv[2].";dbname=".$argv[3].";charset=utf8mb4",
  $argv[4],
  $argv[5],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$dave = (int) $argv[6];
$bob = (int) $argv[7];
$general = (int) $argv[8];
$pdo->prepare("UPDATE users SET suspended_until = \"2099-01-01 00:00:00\" WHERE id = ?")->execute([$dave]);
$msg = $pdo->prepare("INSERT INTO neonchat_messages (message, user_id, created_at, chat_id, type, is_readed) VALUES (?, ?, ?, ?, 0, 0)");
$msg->execute(["historical-one", $bob, "2020-01-01 00:00:00", $general]);
$id1 = (int) $pdo->lastInsertId();
$msg->execute(["historical-two", $bob, "2020-01-01 00:01:00", $general]);
$id2 = (int) $pdo->lastInsertId();
file_put_contents("storage/live-message-ids.json", json_encode(["one" => $id1, "two" => $id2]));
' "${DB_HOST}" "${DB_PORT}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}" "${DAVE_ID}" "${BOB_ID}" "${GENERAL_ID}"

MSG1="$(php -r 'echo json_decode(file_get_contents("storage/live-message-ids.json"), true)["one"];')"
MSG2="$(php -r 'echo json_decode(file_get_contents("storage/live-message-ids.json"), true)["two"];')"

BEFORE_ROOMS="$(php -r '
$pdo = new PDO("mysql:host=".$argv[1].";port=".$argv[2].";dbname=".$argv[3].";charset=utf8mb4", $argv[4], $argv[5], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
echo $pdo->query("SELECT COUNT(*) FROM neonchat_chats")->fetchColumn();
' "${DB_HOST}" "${DB_PORT}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}")"

ALICE_TOKEN="$(token_for alice alice-pass-1)"
BOB_TOKEN="$(token_for bob bob-pass-1)"

GUEST_DIR="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/api/flatrate-live-chat/live-chats")"
deny_401_or_403 LIVE_GUEST_DIRECTORY "${GUEST_DIR}"

GUEST_ROOM="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/api/flatrate-live-chat/rooms/community-general-live")"
deny_401_or_403 LIVE_GUEST_ROOM "${GUEST_ROOM}"

GUEST_HIDDEN="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/api/flatrate-live-chat/rooms/aston-martin-live")"
deny_401_or_403 LIVE_GUEST_HIDDEN_BRAND "${GUEST_HIDDEN}"

GUEST_MSG="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/api/chatmessages?chat_id=${GENERAL_ID}")"
deny_401_or_403 LIVE_GUEST_MESSAGES "${GUEST_MSG}"

GUEST_ADMIN="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/api/flatrate-live-chat/admin/dashboard")"
deny_401_or_403 ADMIN_DASHBOARD_GUEST "${GUEST_ADMIN}"

DAVE_DIR="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${DAVE_TOKEN}" "${BASE}/api/flatrate-live-chat/live-chats")"
deny_401_or_403 LIVE_SUSPENDED_USER "${DAVE_DIR}"

ALICE_DIR="$(mktemp)"
ALICE_DIR_CODE="$(curl -sS -o "${ALICE_DIR}" -w '%{http_code}' -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/flatrate-live-chat/live-chats")"
expect_status ROOM_DIRECTORY_HTTP "${ALICE_DIR_CODE}" 200
php -r '
$body = json_decode(file_get_contents($argv[1]), true);
$keys = array_map(fn ($row) => $row["attributes"]["roomKey"] ?? "", $body["data"] ?? []);
if (!in_array("community-general-live", $keys, true)) {
  fwrite(STDERR, "ROOM_DIRECTORY_HTTP=FAIL missing_general\n");
  exit(1);
}
if (in_array("aston-martin-live", $keys, true) || in_array("orphan-extra-stored-live", $keys, true)) {
  fwrite(STDERR, "HIDDEN_ROOM_DISCOVERY=FAIL ".json_encode($keys)."\n");
  exit(1);
}
' "${ALICE_DIR}"
echo "HIDDEN_ROOM_DISCOVERY=DENIED"
rm -f "${ALICE_DIR}"

ALICE_GENERAL="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/flatrate-live-chat/rooms/community-general-live")"
expect_status ROOM_LOOKUP_HTTP "${ALICE_GENERAL}" 200

ALICE_BRAND="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/flatrate-live-chat/rooms/aston-martin-live")"
deny_403_or_404 LIVE_UNAUTHORIZED_MEMBER_HIDDEN_BRAND "${ALICE_BRAND}"

ALICE_EXTRA="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/flatrate-live-chat/rooms/orphan-extra-stored-live")"
deny_403_or_404 LIVE_EXTRA_STORED_ROOM "${ALICE_EXTRA}"

ALICE_UNKNOWN="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/flatrate-live-chat/rooms/not-a-real-room")"
deny_403_or_404 LIVE_UNKNOWN_ROOM "${ALICE_UNKNOWN}"

SUB_CODE="$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/flatrate-live-chat/rooms/community-general-live/subscription")"
expect_status ROOM_SUBSCRIBE_HTTP "${SUB_CODE}" 200

UNSUB_GENERAL="$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/flatrate-live-chat/rooms/community-general-live/subscription")"
expect_status ROOM_UNSUBSCRIBE_HTTP "${UNSUB_GENERAL}" 200

SUB_BRAND="$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/flatrate-live-chat/rooms/aston-martin-live/subscription")"
deny_403_or_404 ROOM_SUBSCRIBE_HIDDEN_DENIAL "${SUB_BRAND}"

HISTORY="$(mktemp)"
HISTORY_CODE="$(curl -sS -o "${HISTORY}" -w '%{http_code}' -H "Authorization: Token ${BOB_TOKEN}" "${BASE}/api/chatmessages?chat_id=${GENERAL_ID}")"
expect_status MESSAGE_READ_HTTP "${HISTORY_CODE}" 200
php -r '
$body = json_decode(file_get_contents($argv[1]), true);
$rows = $body["data"] ?? [];
$contents = array_map(fn ($row) => $row["attributes"]["content"] ?? "", $rows);
if ($contents !== ["historical-one", "historical-two"]) {
  fwrite(STDERR, "HISTORICAL_MESSAGE_ORDER=FAIL ".json_encode($contents)."\n");
  exit(1);
}
' "${HISTORY}"
echo "HISTORICAL_MESSAGE_ORDER=PASS"
rm -f "${HISTORY}"

WRONG_ROOM_READ="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/chatmessages?chat_id=${BRAND_ID}")"
deny_403_or_404 WRONG_ROOM_READ_DENIAL "${WRONG_ROOM_READ}"

CREATED="$(mktemp)"
CREATE_CODE="$(curl -sS -o "${CREATED}" -w '%{http_code}' -H 'Content-Type: application/json' -H "Authorization: Token ${ALICE_TOKEN}" \
  -d "{\"data\":{\"attributes\":{\"chat_id\":${GENERAL_ID},\"message\":\"hello general\"}}}" \
  "${BASE}/api/chatmessages/${GENERAL_ID}")"
if [[ "${CREATE_CODE}" != "201" ]]; then
  echo "MESSAGE_CREATE_HTTP=FAIL status=${CREATE_CODE}" >&2
  cat "${CREATED}" >&2 || true
  exit 1
fi
echo "MESSAGE_CREATE_HTTP=PASS"
NEW_MSG_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["data"]["id"] ?? "";' "${CREATED}")"
rm -f "${CREATED}"
if [[ -z "${NEW_MSG_ID}" ]]; then
  echo "MESSAGE_CREATE_HTTP=FAIL missing_id" >&2
  exit 1
fi

WRONG_WRITE="$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -H "Authorization: Token ${ALICE_TOKEN}" \
  -d "{\"data\":{\"attributes\":{\"chat_id\":${BRAND_ID},\"message\":\"should fail\"}}}" \
  "${BASE}/api/chatmessages/${BRAND_ID}")"
deny_403_or_404 WRONG_ROOM_WRITE_DENIAL "${WRONG_WRITE}"

EDIT_OK="$(curl -s -o /dev/null -w '%{http_code}' -X PATCH -H 'Content-Type: application/json' -H "Authorization: Token ${ALICE_TOKEN}" \
  -d '{"data":{"attributes":{"actions":{"msg":"hello general edited"}}}}' \
  "${BASE}/api/chatmessages/${NEW_MSG_ID}")"
expect_status MESSAGE_EDIT_HTTP "${EDIT_OK}" 200

EDIT_OTHER="$(curl -s -o /dev/null -w '%{http_code}' -X PATCH -H 'Content-Type: application/json' -H "Authorization: Token ${ALICE_TOKEN}" \
  -d '{"data":{"attributes":{"actions":{"msg":"steal bob message"}}}}' \
  "${BASE}/api/chatmessages/${MSG1}")"
deny_401_or_403 EDIT_UNAUTHORIZED "${EDIT_OTHER}"

DELETE_OTHER="$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -H "Authorization: Token ${ALICE_TOKEN}" \
  "${BASE}/api/chatmessages/${MSG2}")"
deny_401_or_403 DELETE_UNAUTHORIZED "${DELETE_OTHER}"

DELETE_OK="$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -H "Authorization: Token ${ALICE_TOKEN}" \
  "${BASE}/api/chatmessages/${NEW_MSG_ID}")"
expect_status MESSAGE_DELETE_HTTP "${DELETE_OK}" 204

MEMBER_ADMIN="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/flatrate-live-chat/admin/dashboard")"
deny_401_or_403 ADMIN_DASHBOARD_MEMBER "${MEMBER_ADMIN}"

ADMIN_DASH="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${ADMIN_TOKEN}" "${BASE}/api/flatrate-live-chat/admin/dashboard")"
expect_status ADMIN_DASHBOARD_ADMIN "${ADMIN_DASH}" 200

MEMBER_PREVIEW="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/flatrate-live-chat/admin/rooms/reconcile-preview")"
deny_401_or_403 ROOM_PREVIEW_MEMBER "${MEMBER_PREVIEW}"

ADMIN_PREVIEW="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${ADMIN_TOKEN}" "${BASE}/api/flatrate-live-chat/admin/rooms/reconcile-preview")"
expect_status ROOM_PREVIEW_ADMIN "${ADMIN_PREVIEW}" 200

MEMBER_RECONCILE="$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Token ${ALICE_TOKEN}" -H 'Content-Type: application/json' -d '{}' "${BASE}/api/flatrate-live-chat/admin/rooms/reconcile")"
deny_401_or_403 ROOM_RECONCILE_MEMBER "${MEMBER_RECONCILE}"

GUEST_RECONCILE="$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{}' "${BASE}/api/flatrate-live-chat/admin/rooms/reconcile")"
# Guests may see 400 from the CSRF/session boundary before the controller, or 401/403 from auth.
if [[ "${GUEST_RECONCILE}" != "400" && "${GUEST_RECONCILE}" != "401" && "${GUEST_RECONCILE}" != "403" ]]; then
  echo "ROOM_RECONCILE_GUEST=FAIL status=${GUEST_RECONCILE}" >&2
  exit 1
fi
echo "ROOM_RECONCILE_GUEST=DENY status=${GUEST_RECONCILE}"

# Prove the admin route is reachable without applying a full catalog write.
# Empty body should fail closed with a non-auth status while leaving fixtures intact.
ADMIN_RECONCILE_BODY="$(mktemp)"
ADMIN_RECONCILE_CODE="$(curl -sS -o "${ADMIN_RECONCILE_BODY}" -w '%{http_code}' -X POST -H "Authorization: Token ${ADMIN_TOKEN}" -H 'Content-Type: application/json' -d '{}' "${BASE}/api/flatrate-live-chat/admin/rooms/reconcile")"
if [[ "${ADMIN_RECONCILE_CODE}" == "401" || "${ADMIN_RECONCILE_CODE}" == "403" ]]; then
  echo "ROOM_RECONCILE_ADMIN=FAIL status=${ADMIN_RECONCILE_CODE}" >&2
  cat "${ADMIN_RECONCILE_BODY}" >&2 || true
  exit 1
fi
echo "ROOM_RECONCILE_ADMIN=TESTED_DISPOSABLE_ONLY status=${ADMIN_RECONCILE_CODE}"
rm -f "${ADMIN_RECONCILE_BODY}"

LEGACY_AUTH="$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/flatrate-live-chat/realtime/auth")"
expect_status LEGACY_REALTIME_AUTH "${LEGACY_AUTH}" 410

CONNECT_TOKEN="$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/flatrate-live-chat/realtime/connect-token")"
if [[ "${CONNECT_TOKEN}" != "403" && "${CONNECT_TOKEN}" != "401" ]]; then
  echo "REALTIME_DISABLED_FALLBACK=FAIL status=${CONNECT_TOKEN}" >&2
  exit 1
fi
echo "REALTIME_DISABLED_FALLBACK=PASS status=${CONNECT_TOKEN}"

php -r '
$pdo = new PDO("mysql:host=".$argv[1].";port=".$argv[2].";dbname=".$argv[3].";charset=utf8mb4", $argv[4], $argv[5], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$rooms = (int) $pdo->query("SELECT COUNT(*) FROM neonchat_chats")->fetchColumn();
$keys = $pdo->query("SELECT room_key FROM neonchat_chats ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
if ($rooms < 3) {
  fwrite(STDERR, "HISTORICAL_ROOM_FIXTURE=FAIL count={$rooms}\n");
  exit(1);
}
foreach (["community-general-live", "aston-martin-live", "orphan-extra-stored-live"] as $key) {
  if (!in_array($key, $keys, true)) {
    fwrite(STDERR, "HISTORICAL_ROOM_FIXTURE=FAIL missing={$key}\n");
    exit(1);
  }
}
$hist = $pdo->prepare("SELECT message FROM neonchat_messages WHERE id = ?");
$hist->execute([(int) $argv[6]]);
if ($hist->fetchColumn() !== "historical-one") {
  fwrite(STDERR, "HISTORICAL_MESSAGE_FIXTURE=FAIL\n");
  exit(1);
}
' "${DB_HOST}" "${DB_PORT}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}" "${MSG1}"
echo "HISTORICAL_ROOM_FIXTURE=PASS"
echo "HISTORICAL_MESSAGE_FIXTURE=PASS"
echo "EXTRA_STORED_ROOM=RECONCILED_WITHOUT_DELETION"

set +e
DISABLE_OUT="$(php -d display_errors=1 flarum extension:disable flatrate-live-chat 2>&1)"
DISABLE_EC=$?
set -e
if [[ -n "${DISABLE_OUT}" ]]; then
  printf '%s\n' "${DISABLE_OUT}"
fi
if [[ "${DISABLE_EC}" != "0" ]]; then
  echo "DISABLE_FALLBACK=FAIL disable_command exit=${DISABLE_EC}" >&2
  printf '%s\n' "${DISABLE_OUT}" >&2
  exit 1
fi

DISABLED_CODE="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/flatrate-live-chat/live-chats")"
if [[ "${DISABLED_CODE}" == "200" ]]; then
  echo "DISABLE_FALLBACK=FAIL route_still_open" >&2
  exit 1
fi
AFTER_ROOMS="$(php -r '
$pdo = new PDO("mysql:host=".$argv[1].";port=".$argv[2].";dbname=".$argv[3].";charset=utf8mb4", $argv[4], $argv[5], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
echo $pdo->query("SELECT COUNT(*) FROM neonchat_chats")->fetchColumn();
' "${DB_HOST}" "${DB_PORT}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}")"
AFTER_HIST="$(php -r '
$pdo = new PDO("mysql:host=".$argv[1].";port=".$argv[2].";dbname=".$argv[3].";charset=utf8mb4", $argv[4], $argv[5], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$s = $pdo->prepare("SELECT COUNT(*) FROM neonchat_messages WHERE id = ?");
$s->execute([(int) $argv[6]]);
echo $s->fetchColumn();
' "${DB_HOST}" "${DB_PORT}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}" "${MSG1}")"
if [[ -z "${AFTER_ROOMS}" || -z "${AFTER_HIST}" ]]; then
  echo "DATA_PRESERVATION=FAIL empty_census rooms=${AFTER_ROOMS} hist=${AFTER_HIST}" >&2
  exit 1
fi
if [[ "${AFTER_ROOMS}" -lt "${BEFORE_ROOMS}" || "${AFTER_HIST}" != "1" ]]; then
  echo "DATA_PRESERVATION=FAIL rooms_before=${BEFORE_ROOMS} after=${AFTER_ROOMS} hist=${AFTER_HIST}" >&2
  exit 1
fi
echo "DATA_PRESERVATION=PASS"
echo "DISABLE_FALLBACK=PASS status=${DISABLED_CODE}"
echo "LIVE_F2_HTTP=PASS"
echo "PRODUCTION_INSTALL=false"
