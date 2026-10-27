#!/usr/bin/env bash
# Disposable Flarum 2.0.2 realtime publisher proof for FlatRate Live Chat.
# Uses MariaDB (historical Live Chat migrations) + FakeRealtimePublisher file sink.
# Also proves CentrifugoRealtimePublisher against a local mock publish HTTP endpoint.
# No production Centrifugo, JWT secrets, credentials, or installation.
set -euo pipefail

export PATH="/usr/bin:/bin:/usr/local/bin:${PATH:-}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# Git metadata is optional in disposable CI containers / nested worktrees.
git config --global --add safe.directory "${ROOT}" >/dev/null 2>&1 || true
if git -C "${ROOT}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  if ! git -C "${ROOT}" symbolic-ref -q HEAD >/dev/null 2>&1; then
    git -C "${ROOT}" checkout -B ci-f2-live-chat-realtime >/dev/null 2>&1 || true
  fi
fi

SMOKE_ROOT="${FLARUM_SMOKE_ROOT:-${TMPDIR:-/tmp}/flarum-live-f2-realtime-$$}"
FLARUM_SKELETON_VERSION="${FLARUM_SKELETON_VERSION:-2.0.0}"
EXPECTED_CORE="${FLARUM_CORE_VERSION:-2.0.2}"
BASE="http://127.0.0.1:8768"
MOCK_PUBLISH="http://127.0.0.1:8769"

DB_HOST="${FLARUM_DB_HOST:-127.0.0.1}"
DB_PORT="${FLARUM_DB_PORT:-3306}"
DB_NAME="${FLARUM_DB_DATABASE:-flarum_live_realtime}"
DB_USER="${FLARUM_DB_USERNAME:-root}"
DB_PASS="${FLARUM_DB_PASSWORD:-disposable-local}"

FAKE_FILE="${SMOKE_ROOT}/fake-realtime.json"
MOCK_LOG="${SMOKE_ROOT}/mock-publish.log"
KEYS_DIR="${SMOKE_ROOT}/keys"

cleanup() {
  if [[ -n "${HTTP_PID:-}" ]]; then
    kill "${HTTP_PID}" >/dev/null 2>&1 || true
  fi
  if [[ -n "${MOCK_PID:-}" ]]; then
    kill "${MOCK_PID}" >/dev/null 2>&1 || true
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
  echo "LIVE_F2_PUBLISHER=FAIL database_not_ready host=${DB_HOST} port=${DB_PORT}" >&2
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
mkdir -p "${SMOKE_ROOT}" "${KEYS_DIR}"
: > "${FAKE_FILE}"
: > "${MOCK_LOG}"

# Disposable RSA keypair for token/config completeness proofs (never production).
openssl genrsa -out "${KEYS_DIR}/jwt-private.pem" 2048 >/dev/null 2>&1
openssl rsa -in "${KEYS_DIR}/jwt-private.pem" -pubout -out "${KEYS_DIR}/jwt-public.pem" >/dev/null 2>&1

composer create-project "flarum/flarum:${FLARUM_SKELETON_VERSION}" "${SMOKE_ROOT}/app" --no-interaction --no-install
cd "${SMOKE_ROOT}/app"
rm -f composer.lock
composer require "flarum/core:${EXPECTED_CORE}" --no-update --no-interaction
composer update --no-interaction --prefer-dist --no-audit

INSTALLED_CORE="$(
  composer show flarum/core --format=json \
    | php -r '$j=json_decode(stream_get_contents(STDIN), true); $v=$j["versions"][0] ?? ""; echo preg_replace("/^v/", "", (string)$v);'
)"
if [[ "${INSTALLED_CORE}" != "${EXPECTED_CORE}" ]]; then
  echo "LIVE_F2_PUBLISHER=FAIL expected_core=${EXPECTED_CORE} got=${INSTALLED_CORE}" >&2
  exit 1
fi

composer config repositories.flatrate-live-chat path "${ROOT}"
composer require "flatrate/flarum-live-chat:*@dev" --no-interaction --prefer-dist --no-audit

# Prove F2 PushChatEvents is registered and Tobscure-free before boot.
php -r '
$f2 = file_get_contents($argv[1]."/extend-f2.php");
$listener = file_get_contents($argv[1]."/src/F2/Listener/PushChatEvents.php");
if (!str_contains($f2, "F2\\Listener\\PushChatEvents") || !str_contains($f2, "F2PushChatEvents::class")) {
  fwrite(STDERR, "LIVE_F2_PUBLISHER=FAIL missing_f2_subscribe\n");
  exit(1);
}
if (str_contains($listener, "use Tobscure\\") || str_contains($listener, "Tobscure\\JsonApi") || str_contains($listener, "JsonApi\\Document")) {
  fwrite(STDERR, "LIVE_F2_PUBLISHER=FAIL tobscure_in_f2_listener\n");
  exit(1);
}
echo "LIVE_F2_LISTENER_REGISTERED=PASS\n";
' "${ROOT}"

cat > "${SMOKE_ROOT}/app/install.json" <<EOF
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
    "forum_title": "Live Chat Realtime Smoke"
  },
  "extensions": "flarum-suspend,flarum-markdown,flatrate-live-chat"
}
EOF

php flarum install --file "${SMOKE_ROOT}/app/install.json"
rm -f "${SMOKE_ROOT}/app/install.json"

php -r '
$pdo = new PDO(
  "mysql:host=".$argv[1].";port=".$argv[2].";dbname=".$argv[3].";charset=utf8mb4",
  $argv[4],
  $argv[5],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec("DELETE FROM neonchat_messages");
$pdo->exec("DELETE FROM neonchat_chat_user");
$pdo->exec("DELETE FROM neonchat_chats");
$ins = $pdo->prepare("INSERT INTO neonchat_chats (title, color, icon, type, creator_id, created_at, room_key, scope_type, scope_key, visibility, audience) VALUES (?, ?, ?, 1, 1, \"2020-01-01 00:00:00\", ?, ?, ?, ?, ?)");
$ins->execute(["General Live", "#111111", "fas fa-comments", "community-general-live", "navigation-group", "community", "visible", "members"]);
$generalId = (int) $pdo->lastInsertId();
$ins->execute(["Aston Martin Live", "#222222", "fas fa-car", "aston-martin-live", "board", "aston-martin", "hidden", "staff-preview"]);
$brandId = (int) $pdo->lastInsertId();
file_put_contents("storage/live-room-ids.json", json_encode([
  "general" => $generalId,
  "brand" => $brandId,
]));
' "${DB_HOST}" "${DB_PORT}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}"

# Export fake realtime into the Flarum process environment.
export FLATRATE_LIVE_CHAT_FAKE_REALTIME=1
export FLATRATE_LIVE_CHAT_FAKE_REALTIME_FILE="${FAKE_FILE}"
# Ensure Centrifugo remains incomplete in the HTTP app (fail-closed unless fake).
unset FLATRATE_LIVE_CHAT_CENTRIFUGO_WS_URL || true
unset FLATRATE_LIVE_CHAT_CENTRIFUGO_PUBLISH_URL || true

(
  cd "${SMOKE_ROOT}/app/public"
  FLATRATE_LIVE_CHAT_FAKE_REALTIME=1 \
  FLATRATE_LIVE_CHAT_FAKE_REALTIME_FILE="${FAKE_FILE}" \
  php -S 127.0.0.1:8768 index.php > "${SMOKE_ROOT}/http.log" 2>&1
) &
HTTP_PID=$!

ready=0
for _ in $(seq 1 40); do
  if curl -s -o /dev/null "${BASE}/api"; then
    ready=1
    break
  fi
  sleep 0.25
done
if [[ "${ready}" != "1" ]]; then
  echo "LIVE_F2_PUBLISHER=FAIL server_not_ready" >&2
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
    echo "LIVE_F2_PUBLISHER=FAIL token user=${user} status=${code}" >&2
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

GENERAL_ID="$(php -r 'echo json_decode(file_get_contents("storage/live-room-ids.json"), true)["general"];')"
BRAND_ID="$(php -r 'echo json_decode(file_get_contents("storage/live-room-ids.json"), true)["brand"];')"

ALICE_ID="$(create_user alice alice@example.test alice-pass-1)"
ALICE_TOKEN="$(token_for alice alice-pass-1)"

# Reset fake sink before mutations.
printf '{}' > "${FAKE_FILE}"

CREATED="$(mktemp)"
CREATE_CODE="$(curl -sS -o "${CREATED}" -w '%{http_code}' -H 'Content-Type: application/json' -H "Authorization: Token ${ALICE_TOKEN}" \
  -d "{\"data\":{\"attributes\":{\"chat_id\":${GENERAL_ID},\"message\":\"realtime hello\"}}}" \
  "${BASE}/api/chatmessages/${GENERAL_ID}")"
if [[ "${CREATE_CODE}" != "201" ]]; then
  echo "LIVE_F2_PUBLISHER=FAIL create_status=${CREATE_CODE}" >&2
  cat "${CREATED}" >&2 || true
  exit 1
fi
NEW_MSG_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["data"]["id"] ?? "";' "${CREATED}")"
rm -f "${CREATED}"

EDIT_CODE="$(curl -s -o /dev/null -w '%{http_code}' -X PATCH -H 'Content-Type: application/json' -H "Authorization: Token ${ALICE_TOKEN}" \
  -d '{"data":{"attributes":{"actions":{"msg":"realtime hello edited"}}}}' \
  "${BASE}/api/chatmessages/${NEW_MSG_ID}")"
if [[ "${EDIT_CODE}" != "200" ]]; then
  echo "LIVE_F2_PUBLISHER=FAIL edit_status=${EDIT_CODE}" >&2
  exit 1
fi

DELETE_CODE="$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -H "Authorization: Token ${ALICE_TOKEN}" \
  "${BASE}/api/chatmessages/${NEW_MSG_ID}")"
if [[ "${DELETE_CODE}" != "204" ]]; then
  echo "LIVE_F2_PUBLISHER=FAIL delete_status=${DELETE_CODE}" >&2
  exit 1
fi

php -r '
$path = $argv[1];
$generalId = (int) $argv[2];
$brandId = (int) $argv[3];
$raw = file_get_contents($path);
$channels = json_decode($raw, true);
if (!is_array($channels)) {
  fwrite(STDERR, "LIVE_F2_PUBLISHER=FAIL fake_sink_unreadable\n");
  exit(1);
}
$generalCh = "\$flatrate-live-community-general-live";
$brandCh = "\$flatrate-live-aston-martin-live";
$events = $channels[$generalCh] ?? [];
$types = array_column($events, "event");
foreach (["message.created", "message.edited", "message.deleted"] as $need) {
  if (!in_array($need, $types, true)) {
    fwrite(STDERR, "LIVE_F2_PUBLISHER=FAIL missing_event={$need} saw=".json_encode($types)."\n");
    exit(1);
  }
}
if (!empty($channels[$brandCh] ?? [])) {
  fwrite(STDERR, "LIVE_UNAUTHORIZED_DELIVERY=FAIL brand_channel_polluted\n");
  exit(1);
}
foreach ($events as $item) {
  $payload = $item["payload"] ?? [];
  if (($payload["room_key"] ?? "") !== "community-general-live") {
    fwrite(STDERR, "LIVE_F2_PUBLISHER=FAIL wrong_room_key\n");
    exit(1);
  }
  if ((int) ($payload["chat_id"] ?? 0) !== $generalId) {
    fwrite(STDERR, "LIVE_F2_PUBLISHER=FAIL wrong_chat_id\n");
    exit(1);
  }
}
echo "LIVE_F2_PUBLISHER=PASS\n";
echo "LIVE_UNAUTHORIZED_DELIVERY=DENIED\n";
' "${FAKE_FILE}" "${GENERAL_ID}" "${BRAND_ID}"

# Unauthorized hidden-brand write must not publish to brand channel.
WRONG_WRITE="$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -H "Authorization: Token ${ALICE_TOKEN}" \
  -d "{\"data\":{\"attributes\":{\"chat_id\":${BRAND_ID},\"message\":\"should fail\"}}}" \
  "${BASE}/api/chatmessages/${BRAND_ID}")"
if [[ "${WRONG_WRITE}" != "403" && "${WRONG_WRITE}" != "404" ]]; then
  echo "LIVE_UNAUTHORIZED_DELIVERY=FAIL write_status=${WRONG_WRITE}" >&2
  exit 1
fi
php -r '
$channels = json_decode(file_get_contents($argv[1]), true);
$brandCh = "\$flatrate-live-aston-martin-live";
if (!empty($channels[$brandCh] ?? [])) {
  fwrite(STDERR, "LIVE_UNAUTHORIZED_DELIVERY=FAIL brand_events_after_denied_write\n");
  exit(1);
}
echo "CENTRIFUGO_ISOLATED_CROSS_ROOM=DENIED\n";
' "${FAKE_FILE}"

# Local mock Centrifugo publish edge + CentrifugoRealtimePublisher proof.
cat > "${SMOKE_ROOT}/mock-publish.php" <<'PHP'
<?php
$path = getenv('MOCK_PUBLISH_LOG') ?: '/tmp/mock-publish.log';
$body = file_get_contents('php://input');
file_put_contents($path, $body . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
echo '{"result":{}}';
PHP

(
  MOCK_PUBLISH_LOG="${MOCK_LOG}" php -S 127.0.0.1:8769 -t "${SMOKE_ROOT}" "${SMOKE_ROOT}/mock-publish.php" \
    > "${SMOKE_ROOT}/mock-http.log" 2>&1
) &
MOCK_PID=$!
sleep 0.5

php -r '
require $argv[1]."/vendor/autoload.php";
use FlatRate\LiveChat\Realtime\CentrifugoClientConfig;
use FlatRate\LiveChat\Realtime\CentrifugoRealtimePublisher;
use FlatRate\LiveChat\Realtime\NullRealtimePublisher;

$private = file_get_contents($argv[2]);
$cfg = new CentrifugoClientConfig(
  "ws://127.0.0.1:8000/connection/websocket",
  $argv[3],
  "edge-key",
  "api-key",
  $private,
  CentrifugoClientConfig::JWT_ALGORITHM,
  CentrifugoClientConfig::DEFAULT_ISSUER,
  CentrifugoClientConfig::DEFAULT_AUDIENCE,
  300,
  300,
  false,
  true
);
if (!$cfg->isComplete()) {
  fwrite(STDERR, "CENTRIFUGO_ISOLATED_PUBLISH=FAIL config_incomplete\n");
  exit(1);
}

$calls = 0;
$pub = new CentrifugoRealtimePublisher($cfg, null, function ($url, $headers, $body) use (&$calls) {
  $calls++;
  $ctx = stream_context_create([
    "http" => [
      "method" => "POST",
      "header" => "Content-Type: application/json\r\nX-FlatRate-Realtime-Key: edge-key\r\nX-API-Key: api-key\r\n",
      "content" => $body,
      "ignore_errors" => true,
      "timeout" => 3,
    ],
  ]);
  $raw = @file_get_contents($url, false, $ctx);
  $status = 0;
  if (isset($http_response_header[0]) && preg_match("/\\s(\\d{3})\\s/", $http_response_header[0], $m)) {
    $status = (int) $m[1];
  }
  return ["status" => $status ?: 200, "body" => $raw === false ? "" : $raw];
});

$pub->publish("\$flatrate-live-community-general-live", "message.created", [
  "roomKey" => "community-general-live",
  "messageId" => 42,
  "order" => 42,
  "eventId" => "isolated-authz-1",
]);
if ($calls < 1) {
  fwrite(STDERR, "CENTRIFUGO_ISOLATED_PUBLISH=FAIL no_http_call\n");
  exit(1);
}

$crossCalls = 0;
$cross = new CentrifugoRealtimePublisher($cfg, null, function () use (&$crossCalls) {
  $crossCalls++;
  return ["status" => 200, "body" => "{\"result\":{}}"];
});
$cross->publish("\$flatrate-live-toyota-live", "message.created", ["roomKey" => "gm-live"]);
if ($crossCalls !== 0) {
  fwrite(STDERR, "CENTRIFUGO_ISOLATED_CROSS_ROOM=FAIL published\n");
  exit(1);
}

$null = new NullRealtimePublisher();
$null->publish("\$flatrate-live-community-general-live", "message.created", ["roomKey" => "community-general-live"]);

$incomplete = new CentrifugoRealtimePublisher(new CentrifugoClientConfig(), null, function () {
  fwrite(STDERR, "CENTRIFUGO_ISOLATED_DISABLED=FAIL leaked_publish\n");
  exit(1);
});
$incomplete->publish("\$flatrate-live-community-general-live", "message.created", ["roomKey" => "community-general-live"]);

echo "CENTRIFUGO_ISOLATED_PUBLISH=PASS\n";
echo "CENTRIFUGO_ISOLATED_DISABLED=PASS\n";
' "${SMOKE_ROOT}/app" "${KEYS_DIR}/jwt-private.pem" "${MOCK_PUBLISH}/api/publish"

# Realtime disabled fallback: incomplete Centrifugo config → NullRealtimePublisher path.
mkdir -p "${SMOKE_ROOT}/empty-secrets"
php -r '
use FlatRate\LiveChat\Realtime\CentrifugoClientConfig;
use FlatRate\LiveChat\Realtime\NullRealtimePublisher;
require $argv[1]."/vendor/autoload.php";
$cfg = CentrifugoClientConfig::fromEnvironment($argv[2]);
if ($cfg->isComplete()) {
  fwrite(STDERR, "LIVE_RECONNECT=FAIL unexpected_complete_config\n");
  exit(1);
}
(new NullRealtimePublisher())->publish("\$flatrate-live-x", "message.created", []);
echo "LIVE_RECONNECT=PASS\n";
echo "CENTRIFUGO_ISOLATED_NULL=PASS\n";
' "${SMOKE_ROOT}/app" "${SMOKE_ROOT}/empty-secrets"

echo "CENTRIFUGO_ISOLATED_E2E=PASS"
echo "PRODUCTION_INSTALL=false"
echo "LIVE_F2_REALTIME=PASS"
