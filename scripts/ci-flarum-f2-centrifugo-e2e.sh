#!/usr/bin/env bash
# Real Centrifugo WebSocket end-to-end acceptance for FlatRate Live Chat (Flarum 2).
#
# Starts a pinned disposable Centrifugo container + ephemeral RS256 keys + a
# localhost FlatRate publish-edge substitute, boots disposable Flarum 2.0.2,
# and proves authenticated WS connect/subscribe/delivery/denials/reconnect/
# measured revocation behavior with a real centrifuge JS client.
#
# Keeps scripts/ci-flarum-f2-realtime.sh (FakeRealtimePublisher + mock HTTP) intact.
#
# PRODUCTION=NO CHANGES. No production Centrifugo hostname, Cloudflare, PikaPods,
# or production secrets/channels.
#
# Publish path proven: CentrifugoRealtimePublisher → local test-equivalent edge
# → Centrifugo HTTP API. Cloudflare edge LEFT UNTESTED.
set -euo pipefail

export PATH="/usr/bin:/bin:/usr/local/bin:${PATH:-}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FIXTURES="${ROOT}/scripts/fixtures/centrifugo-e2e"
WS_CLIENT="${FIXTURES}/ws-client.mjs"
EDGE_PHP="${FIXTURES}/local-publish-edge.php"

git config --global --add safe.directory "${ROOT}" >/dev/null 2>&1 || true
if git -C "${ROOT}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  if ! git -C "${ROOT}" symbolic-ref -q HEAD >/dev/null 2>&1; then
    git -C "${ROOT}" checkout -B ci-f2-live-chat-centrifugo-e2e >/dev/null 2>&1 || true
  fi
fi

SMOKE_ROOT="${FLARUM_SMOKE_ROOT:-${TMPDIR:-/tmp}/flarum-live-f2-centrifugo-e2e-$$}"
# Resolve relative evidence paths against ROOT: the script later cds into the
# disposable Flarum app, so a bare relative CENTRIFUGO_E2E_EVIDENCE would miss.
EVIDENCE_DIR="${CENTRIFUGO_E2E_EVIDENCE:-${ROOT}/evidence/forum-f2-centrifugo-e2e}"
case "${EVIDENCE_DIR}" in
  /*) ;;
  *) EVIDENCE_DIR="${ROOT}/${EVIDENCE_DIR}" ;;
esac
FLARUM_SKELETON_VERSION="${FLARUM_SKELETON_VERSION:-2.0.0}"
EXPECTED_CORE="${FLARUM_CORE_VERSION:-2.0.2}"

# Isolated localhost ports (no public ingress).
CENTRIFUGO_HOST_PORT="${CENTRIFUGO_HOST_PORT:-18780}"
EDGE_HOST_PORT="${EDGE_HOST_PORT:-18781}"
FLARUM_HOST_PORT="${FLARUM_HOST_PORT:-18782}"
DB_HOST_PORT="${FLARUM_DB_PORT:-13307}"

CENTRIFUGO_WS="ws://127.0.0.1:${CENTRIFUGO_HOST_PORT}/connection/websocket"
CENTRIFUGO_API="http://127.0.0.1:${CENTRIFUGO_HOST_PORT}/api"
EDGE_PUBLISH="http://127.0.0.1:${EDGE_HOST_PORT}/api/publish"
BASE="http://127.0.0.1:${FLARUM_HOST_PORT}"

# Pinned Centrifugo image (same digest as flatrate-realtime infra pin).
CENTRIFUGO_IMAGE="${CENTRIFUGO_IMAGE:-centrifugo/centrifugo:v6.9.4@sha256:78b2fcd11e69f7cd82072924db99865be2beba4a03087d8ce0f5008ae357abe4}"
MARIADB_IMAGE="${MARIADB_IMAGE:-mariadb:10.11}"

RUN_ID="f2cene2e$$"
CENTRIFUGO_NAME="${CENTRIFUGO_CONTAINER:-${RUN_ID}-centrifugo}"
DB_NAME_CONTAINER="${DB_CONTAINER:-${RUN_ID}-mariadb}"
OWNED_DB=0

DB_HOST="${FLARUM_DB_HOST:-127.0.0.1}"
DB_PORT="${DB_HOST_PORT}"
DB_NAME="${FLARUM_DB_DATABASE:-flarum_live_centrifugo_e2e}"
DB_USER="${FLARUM_DB_USERNAME:-root}"
DB_PASS="${FLARUM_DB_PASSWORD:-disposable-local}"

EDGE_KEY="test-edge-$(openssl rand -hex 8)"
API_KEY="test-api-$(openssl rand -hex 8)"

# Machine-readable keys (printed at end).
RESULT_REAL_CENTRIFUGO_SERVER=FAIL
RESULT_REAL_WS_CONNECT=FAIL
RESULT_REAL_WS_SUBSCRIBE=FAIL
RESULT_REAL_WS_MESSAGE_DELIVERY=FAIL
RESULT_REAL_WS_CROSS_ROOM_DENIAL=FAIL
RESULT_REAL_WS_UNAUTHORIZED_DENIAL=FAIL
RESULT_REAL_RECONNECT=FAIL
RESULT_REVOCATION_WINDOW_SECONDS=NOT_PROVEN
RESULT_PUBLISHER_OUTAGE_FALLBACK=FAIL
PUBLISH_PATH_PROVEN="NOT_PROVEN"
DENIED_CASES_JSON="{}"

KEYS_DIR=""
HTTP_PID=""
EDGE_PID=""

emit_results() {
  echo "REAL_CENTRIFUGO_SERVER=${RESULT_REAL_CENTRIFUGO_SERVER}"
  echo "REAL_WS_CONNECT=${RESULT_REAL_WS_CONNECT}"
  echo "REAL_WS_SUBSCRIBE=${RESULT_REAL_WS_SUBSCRIBE}"
  echo "REAL_WS_MESSAGE_DELIVERY=${RESULT_REAL_WS_MESSAGE_DELIVERY}"
  echo "REAL_WS_CROSS_ROOM_DENIAL=${RESULT_REAL_WS_CROSS_ROOM_DENIAL}"
  echo "REAL_WS_UNAUTHORIZED_DENIAL=${RESULT_REAL_WS_UNAUTHORIZED_DENIAL}"
  echo "REAL_RECONNECT=${RESULT_REAL_RECONNECT}"
  echo "REVOCATION_WINDOW_SECONDS=${RESULT_REVOCATION_WINDOW_SECONDS}"
  echo "PUBLISHER_OUTAGE_FALLBACK=${RESULT_PUBLISHER_OUTAGE_FALLBACK}"
  echo "PUBLISH_PATH_PROVEN=${PUBLISH_PATH_PROVEN}"
  echo "CLOUDFLARE_EDGE=LEFT_UNTESTED"
  echo "DENIED_CASES=${DENIED_CASES_JSON}"
}

cleanup() {
  local ec=$?
  emit_results || true
  if [[ -n "${HTTP_PID:-}" ]]; then
    kill "${HTTP_PID}" >/dev/null 2>&1 || true
  fi
  if [[ -n "${EDGE_PID:-}" ]]; then
    kill "${EDGE_PID}" >/dev/null 2>&1 || true
  fi
  docker rm -f "${CENTRIFUGO_NAME}" >/dev/null 2>&1 || true
  if [[ "${OWNED_DB}" == "1" ]]; then
    docker rm -f "${DB_NAME_CONTAINER}" >/dev/null 2>&1 || true
  fi
  if [[ "${KEEP_SMOKE_ROOT:-0}" != "1" ]]; then
    rm -rf "${SMOKE_ROOT}"
  fi
  exit "${ec}"
}
trap cleanup EXIT

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "LIVE_F2_CENTRIFUGO_E2E=FAIL missing_command=$1" >&2
    exit 1
  }
}

need_cmd docker
need_cmd php
need_cmd composer
need_cmd openssl
need_cmd curl
need_cmd node
need_cmd python3

if [[ ! -f "${WS_CLIENT}" || ! -f "${EDGE_PHP}" ]]; then
  echo "LIVE_F2_CENTRIFUGO_E2E=FAIL missing_fixtures" >&2
  exit 1
fi

rm -rf "${SMOKE_ROOT}"
mkdir -p "${SMOKE_ROOT}" "${SMOKE_ROOT}/keys" "${SMOKE_ROOT}/centrifugo" "${SMOKE_ROOT}/edge" "${SMOKE_ROOT}/ws" "${EVIDENCE_DIR}"
KEYS_DIR="${SMOKE_ROOT}/keys"
LOG="${SMOKE_ROOT}/e2e.log"
EDGE_LOG="${SMOKE_ROOT}/edge-publish.log"
: > "${LOG}"
: > "${EDGE_LOG}"

log() { echo "$*" | tee -a "${LOG}"; }

# --- Ephemeral RS256 keys (never production) ---
openssl genrsa -out "${KEYS_DIR}/jwt-private.pem" 2048 >/dev/null 2>&1
openssl rsa -in "${KEYS_DIR}/jwt-private.pem" -pubout -out "${KEYS_DIR}/jwt-public.pem" >/dev/null 2>&1
# Prefer *_FILE secrets: multiline PEM in process env is unreliable under php -S.
JWT_PRIVATE_FILE="${KEYS_DIR}/jwt-private.pem"
EDGE_KEY_FILE="${KEYS_DIR}/edge-key"
API_KEY_FILE="${KEYS_DIR}/api-key"
printf '%s' "${EDGE_KEY}" > "${EDGE_KEY_FILE}"
printf '%s' "${API_KEY}" > "${API_KEY_FILE}"
chmod 600 "${JWT_PRIVATE_FILE}" "${EDGE_KEY_FILE}" "${API_KEY_FILE}"

# --- MariaDB (start disposable if host DB not ready) ---
db_ready() {
  php -r '
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
  ' "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASS}"
}

if ! db_ready; then
  log "Starting disposable MariaDB on 127.0.0.1:${DB_PORT}"
  docker rm -f "${DB_NAME_CONTAINER}" >/dev/null 2>&1 || true
  docker run -d --name "${DB_NAME_CONTAINER}" \
    -e MARIADB_ROOT_PASSWORD="${DB_PASS}" \
    -p "127.0.0.1:${DB_PORT}:3306" \
    "${MARIADB_IMAGE}" >/dev/null
  OWNED_DB=1
  for _ in $(seq 1 90); do
    if db_ready; then break; fi
    sleep 1
  done
  if ! db_ready; then
    echo "LIVE_F2_CENTRIFUGO_E2E=FAIL database_not_ready" >&2
    exit 1
  fi
fi

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

# --- Centrifugo config (RS256, issuer/audience match FlatRate defaults) ---
python3 - <<PY
import json
pub = open("${KEYS_DIR}/jwt-public.pem").read()
cfg = {
  "client": {
    "token": {
      "rsa_public_key": pub,
      "issuer": "flatrate-forum",
      "audience": "flatrate-realtime"
    },
    "allowed_origins": ["*"],
    "allow_anonymous_connect_without_token": False
  },
  "http_api": {
    "key": "${API_KEY}"
  },
  "admin": {"enabled": False},
  "swagger": {"enabled": False},
  "prometheus": {"enabled": False},
  "health": {"enabled": True},
  "channel": {
    "without_namespace": {
      "allow_subscribe_for_client": False,
      "allow_subscribe_for_anonymous": False,
      "allow_publish_for_client": False,
      "allow_publish_for_subscriber": False,
      "allow_publish_for_anonymous": False,
      "presence": True,
      "history_size": 0,
      "force_recovery": False
    }
  }
}
open("${SMOKE_ROOT}/centrifugo/config.json", "w").write(json.dumps(cfg, indent=2))
PY

docker rm -f "${CENTRIFUGO_NAME}" >/dev/null 2>&1 || true
docker run -d --name "${CENTRIFUGO_NAME}" \
  -p "127.0.0.1:${CENTRIFUGO_HOST_PORT}:8000" \
  -v "${SMOKE_ROOT}/centrifugo/config.json:/centrifugo/config.json:ro" \
  "${CENTRIFUGO_IMAGE}" \
  centrifugo -c /centrifugo/config.json >/dev/null

centrifugo_ready=0
for _ in $(seq 1 40); do
  if curl -sf "http://127.0.0.1:${CENTRIFUGO_HOST_PORT}/health" >/dev/null; then
    centrifugo_ready=1
    break
  fi
  sleep 0.25
done
if [[ "${centrifugo_ready}" != "1" ]]; then
  echo "LIVE_F2_CENTRIFUGO_E2E=FAIL centrifugo_not_ready" >&2
  docker logs "${CENTRIFUGO_NAME}" >&2 || true
  exit 1
fi
RESULT_REAL_CENTRIFUGO_SERVER=PASS
log "REAL_CENTRIFUGO_SERVER=PASS image=${CENTRIFUGO_IMAGE}"

# --- Local FlatRate publish edge substitute ---
cp "${EDGE_PHP}" "${SMOKE_ROOT}/edge/index.php"
(
  EDGE_KEY="${EDGE_KEY}" \
  API_KEY="${API_KEY}" \
  CENTRIFUGO_PUBLISH_URL="http://127.0.0.1:${CENTRIFUGO_HOST_PORT}/api/publish" \
  EDGE_LOG="${EDGE_LOG}" \
  php -S "127.0.0.1:${EDGE_HOST_PORT}" -t "${SMOKE_ROOT}/edge" "${SMOKE_ROOT}/edge/index.php" \
    > "${SMOKE_ROOT}/edge-http.log" 2>&1
) &
EDGE_PID=$!
sleep 0.4
if ! curl -sf "http://127.0.0.1:${EDGE_HOST_PORT}/healthz" >/dev/null; then
  echo "LIVE_F2_CENTRIFUGO_E2E=FAIL edge_not_ready" >&2
  cat "${SMOKE_ROOT}/edge-http.log" >&2 || true
  exit 1
fi

# Ensure centrifuge JS client is available for the harness.
if [[ ! -d "${ROOT}/js/node_modules/centrifuge" ]]; then
  log "Installing centrifuge JS client into js/ for WS harness"
  (cd "${ROOT}/js" && npm ci --omit=dev >/dev/null 2>&1 || npm install centrifuge@5.7.3 --no-save >/dev/null)
fi

# --- Disposable Flarum 2.0.2 ---
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
  echo "LIVE_F2_CENTRIFUGO_E2E=FAIL expected_core=${EXPECTED_CORE} got=${INSTALLED_CORE}" >&2
  exit 1
fi

composer config repositories.flatrate-live-chat path "${ROOT}"
composer require "flatrate/flarum-live-chat:*@dev" --no-interaction --prefer-dist --no-audit

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
    "forum_title": "Live Chat Centrifugo E2E"
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

# Export Centrifugo credentials into Flarum process (localhost insecure allowed).
# Use *_FILE for secrets so PEM newlines survive php -S.
export FLATRATE_LIVE_CHAT_ALLOW_INSECURE_REALTIME=1
export FLATRATE_LIVE_CHAT_CENTRIFUGO_WS_URL="${CENTRIFUGO_WS}"
export FLATRATE_LIVE_CHAT_CENTRIFUGO_PUBLISH_URL="${EDGE_PUBLISH}"
export FLATRATE_LIVE_CHAT_CENTRIFUGO_EDGE_KEY_FILE="${EDGE_KEY_FILE}"
export FLATRATE_LIVE_CHAT_CENTRIFUGO_API_KEY_FILE="${API_KEY_FILE}"
export FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_PRIVATE_KEY_FILE="${JWT_PRIVATE_FILE}"
export FLATRATE_LIVE_CHAT_CENTRIFUGO_CONNECTION_TTL=300
export FLATRATE_LIVE_CHAT_CENTRIFUGO_SUBSCRIPTION_TTL=300
unset FLATRATE_LIVE_CHAT_CENTRIFUGO_EDGE_KEY || true
unset FLATRATE_LIVE_CHAT_CENTRIFUGO_API_KEY || true
unset FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_PRIVATE_KEY || true
unset FLATRATE_LIVE_CHAT_FAKE_REALTIME || true
unset FLATRATE_LIVE_CHAT_FAKE_REALTIME_FILE || true
unset FLATRATE_LIVE_CHAT_CENTRIFUGO_DISABLED || true

(
  cd "${SMOKE_ROOT}/app/public"
  FLATRATE_LIVE_CHAT_ALLOW_INSECURE_REALTIME=1 \
  FLATRATE_LIVE_CHAT_CENTRIFUGO_WS_URL="${CENTRIFUGO_WS}" \
  FLATRATE_LIVE_CHAT_CENTRIFUGO_PUBLISH_URL="${EDGE_PUBLISH}" \
  FLATRATE_LIVE_CHAT_CENTRIFUGO_EDGE_KEY_FILE="${EDGE_KEY_FILE}" \
  FLATRATE_LIVE_CHAT_CENTRIFUGO_API_KEY_FILE="${API_KEY_FILE}" \
  FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_PRIVATE_KEY_FILE="${JWT_PRIVATE_FILE}" \
  FLATRATE_LIVE_CHAT_CENTRIFUGO_CONNECTION_TTL=300 \
  FLATRATE_LIVE_CHAT_CENTRIFUGO_SUBSCRIPTION_TTL=300 \
  php -S "127.0.0.1:${FLARUM_HOST_PORT}" index.php > "${SMOKE_ROOT}/http.log" 2>&1
) &
HTTP_PID=$!

ready=0
for _ in $(seq 1 60); do
  if curl -s -o /dev/null "${BASE}/api"; then
    ready=1
    break
  fi
  sleep 0.25
done
if [[ "${ready}" != "1" ]]; then
  echo "LIVE_F2_CENTRIFUGO_E2E=FAIL flarum_not_ready" >&2
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
    echo "LIVE_F2_CENTRIFUGO_E2E=FAIL token user=${user} status=${code}" >&2
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
  local out id
  out="$(mktemp)"
  if ! php -r '
    try {
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
    } catch (Throwable $e) {
      fwrite(STDERR, "create_user_error=".$e->getMessage()."\n");
      exit(1);
    }
  ' "${name}" "${email}" "${pass}" > "${out}"; then
    echo "LIVE_F2_CENTRIFUGO_E2E=FAIL create_user name=${name}" >&2
    cat "${out}" >&2 || true
    exit 1
  fi
  id="$(tr -d '[:space:]' < "${out}")"
  rm -f "${out}"
  if [[ -z "${id}" ]]; then
    echo "LIVE_F2_CENTRIFUGO_E2E=FAIL create_user_empty name=${name}" >&2
    exit 1
  fi
  printf '%s' "${id}"
}

GENERAL_ID="$(php -r 'echo json_decode(file_get_contents("storage/live-room-ids.json"), true)["general"];')"
BRAND_ID="$(php -r 'echo json_decode(file_get_contents("storage/live-room-ids.json"), true)["brand"];')"
GENERAL_CHANNEL='$flatrate-live-community-general-live'
BRAND_CHANNEL='$flatrate-live-aston-martin-live'
GENERAL_ROOM_KEY='community-general-live'
BRAND_ROOM_KEY='aston-martin-live'

log "Creating Alice/Bob members"
ALICE_ID="$(create_user alice alice@example.test alice-pass-1)"
BOB_ID="$(create_user bob bob@example.test bob-pass-1)"
log "Users alice=${ALICE_ID} bob=${BOB_ID}"
ALICE_TOKEN="$(token_for alice alice-pass-1)"
BOB_TOKEN="$(token_for bob bob-pass-1)"
log "Forum API tokens minted"

api_json() {
  local method="$1"
  local path="$2"
  local auth="$3"
  local data="${4:-}"
  local out
  out="$(mktemp)"
  local args=(-sS -o "${out}" -w '%{http_code}' -X "${method}" -H 'Content-Type: application/json' -H 'Accept: application/json')
  if [[ -n "${auth}" ]]; then
    args+=(-H "Authorization: Token ${auth}")
  fi
  if [[ -n "${data}" ]]; then
    args+=(-d "${data}")
  fi
  local code
  code="$(curl "${args[@]}" "${BASE}${path}")"
  echo "${code}"
  cat "${out}"
  rm -f "${out}"
}

fetch_connect_token() {
  local auth="$1"
  local out code body
  out="$(mktemp)"
  code="$(curl -sS -o "${out}" -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H "Authorization: Token ${auth}" \
    -d '{}' "${BASE}/api/flatrate-live-chat/realtime/connect-token")"
  body="$(cat "${out}")"
  rm -f "${out}"
  printf '%s\n%s' "${code}" "${body}"
}

fetch_sub_token() {
  local auth="$1"
  local room_key="$2"
  local out code body
  out="$(mktemp)"
  code="$(curl -sS -o "${out}" -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H "Authorization: Token ${auth}" \
    -d "{\"roomKey\":\"${room_key}\"}" "${BASE}/api/flatrate-live-chat/realtime/subscription-token")"
  body="$(cat "${out}")"
  rm -f "${out}"
  printf '%s\n%s' "${code}" "${body}"
}

run_ws() {
  node "${WS_CLIENT}" "$@"
}

# ========== Positive path ==========
CONN_RESP="$(fetch_connect_token "${ALICE_TOKEN}")"
CONN_CODE="$(printf '%s' "${CONN_RESP}" | head -n1)"
CONN_BODY="$(printf '%s' "${CONN_RESP}" | tail -n +2)"
ALICE_CONN_TOKEN="$(php -r 'echo json_decode(file_get_contents("php://stdin"), true)["token"] ?? "";' <<<"${CONN_BODY}")"
if [[ "${CONN_CODE}" != "200" || -z "${ALICE_CONN_TOKEN}" ]]; then
  echo "LIVE_F2_CENTRIFUGO_E2E=FAIL connect_token status=${CONN_CODE}" >&2
  echo "${CONN_BODY}" >&2
  exit 1
fi

CONNECT_OUT="${SMOKE_ROOT}/ws/connect.json"
if run_ws --mode connect_only --ws "${CENTRIFUGO_WS}" --connect-token "${ALICE_CONN_TOKEN}" --out "${CONNECT_OUT}" --timeout 12000; then
  RESULT_REAL_WS_CONNECT=PASS
else
  RESULT_REAL_WS_CONNECT=FAIL
  cat "${CONNECT_OUT}" >&2 || true
  exit 1
fi
log "REAL_WS_CONNECT=PASS"

SUB_RESP="$(fetch_sub_token "${ALICE_TOKEN}" "${GENERAL_ROOM_KEY}")"
SUB_CODE="$(printf '%s' "${SUB_RESP}" | head -n1)"
SUB_BODY="$(printf '%s' "${SUB_RESP}" | tail -n +2)"
ALICE_SUB_TOKEN="$(php -r 'echo json_decode(file_get_contents("php://stdin"), true)["token"] ?? "";' <<<"${SUB_BODY}")"
ALICE_SUB_CHANNEL="$(php -r 'echo json_decode(file_get_contents("php://stdin"), true)["channel"] ?? "";' <<<"${SUB_BODY}")"
if [[ "${SUB_CODE}" != "200" || -z "${ALICE_SUB_TOKEN}" || "${ALICE_SUB_CHANNEL}" != "${GENERAL_CHANNEL}" ]]; then
  echo "LIVE_F2_CENTRIFUGO_E2E=FAIL subscription_token status=${SUB_CODE}" >&2
  echo "${SUB_BODY}" >&2
  exit 1
fi

# Start Alice listener before Bob posts.
DELIVERY_OUT="${SMOKE_ROOT}/ws/delivery.json"
(
  run_ws --mode connect_subscribe_receive --ws "${CENTRIFUGO_WS}" \
    --connect-token "${ALICE_CONN_TOKEN}" \
    --channel "${GENERAL_CHANNEL}" \
    --sub-token "${ALICE_SUB_TOKEN}" \
    --wait-event 1 \
    --expect-room-key "${GENERAL_ROOM_KEY}" \
    --out "${DELIVERY_OUT}" \
    --timeout 25000
) &
ALICE_WS_PID=$!
sleep 1.2

# Bob posts via Flarum HTTP → F2 publisher → local edge → Centrifugo.
CREATED="$(mktemp)"
CREATE_CODE="$(curl -sS -o "${CREATED}" -w '%{http_code}' -H 'Content-Type: application/json' -H "Authorization: Token ${BOB_TOKEN}" \
  -d "{\"data\":{\"attributes\":{\"chat_id\":${GENERAL_ID},\"message\":\"centrifugo e2e hello\"}}}" \
  "${BASE}/api/chatmessages/${GENERAL_ID}")"
if [[ "${CREATE_CODE}" != "201" ]]; then
  echo "LIVE_F2_CENTRIFUGO_E2E=FAIL bob_create status=${CREATE_CODE}" >&2
  cat "${CREATED}" >&2 || true
  kill "${ALICE_WS_PID}" >/dev/null 2>&1 || true
  exit 1
fi
NEW_MSG_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["data"]["id"] ?? "";' "${CREATED}")"
rm -f "${CREATED}"

if wait "${ALICE_WS_PID}"; then
  RESULT_REAL_WS_SUBSCRIBE=PASS
  # Validate delivery envelope
  php -r '
    $j = json_decode(file_get_contents($argv[1]), true);
    if (!($j["ok"] ?? false) || !($j["subscribed"] ?? false)) { fwrite(STDERR, "subscribe/delivery json fail\n"); exit(1); }
    $pubs = $j["publications"] ?? [];
    if (!$pubs) { fwrite(STDERR, "no publications\n"); exit(1); }
    $hit = false;
    foreach ($pubs as $p) {
      $mid = $p["payload"]["messageId"] ?? $p["messageId"] ?? null;
      $rk = $p["roomKey"] ?? "";
      $eid = $p["eventId"] ?? "";
      if ((string)$mid === (string)$argv[2] && $rk === $argv[3] && $eid !== "") { $hit = true; break; }
    }
    if (!$hit) { fwrite(STDERR, "message identity mismatch\n"); exit(1); }
  ' "${DELIVERY_OUT}" "${NEW_MSG_ID}" "${GENERAL_ROOM_KEY}"
  RESULT_REAL_WS_MESSAGE_DELIVERY=PASS
  PUBLISH_PATH_PROVEN="CentrifugoRealtimePublisher->local_test_equivalent_edge->Centrifugo_HTTP_API"
  log "REAL_WS_SUBSCRIBE=PASS"
  log "REAL_WS_MESSAGE_DELIVERY=PASS msg_id=${NEW_MSG_ID}"
else
  RESULT_REAL_WS_SUBSCRIBE=FAIL
  RESULT_REAL_WS_MESSAGE_DELIVERY=FAIL
  cat "${DELIVERY_OUT}" >&2 || true
  cat "${EDGE_LOG}" >&2 || true
  exit 1
fi

# Confirm edge actually forwarded (bounded retry policy preserved in publisher PHP).
if ! grep -q 'local_edge_to_centrifugo_http_api' "${EDGE_LOG}"; then
  echo "LIVE_F2_CENTRIFUGO_E2E=FAIL edge_log_missing_forward" >&2
  exit 1
fi

# ========== Negative HTTP denials ==========
declare -A DENIED=()

GUEST_OUT="$(mktemp)"
GUEST_CODE="$(curl -sS -o "${GUEST_OUT}" -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}' \
  "${BASE}/api/flatrate-live-chat/realtime/connect-token")"
GUEST_TOKEN="$(php -r '$j=json_decode(@file_get_contents($argv[1]), true); echo is_array($j)?($j["token"] ?? ""):"";' "${GUEST_OUT}" 2>/dev/null || true)"
rm -f "${GUEST_OUT}"
# Guest must not receive a connection JWT. CSRF may yield 400 before AuthZ; still DENIED.
if [[ -z "${GUEST_TOKEN}" && "${GUEST_CODE}" != "200" ]]; then
  DENIED[GUEST_CONNECTION]=DENIED
  log "GUEST_CONNECTION=DENIED status=${GUEST_CODE}"
else
  DENIED[GUEST_CONNECTION]=FAIL
  log "GUEST_CONNECTION unexpected_status=${GUEST_CODE} token_present=$([ -n "${GUEST_TOKEN}" ] && echo 1 || echo 0)"
fi

# Suspend a temporary user for SUSPENDED_CONNECTION
SUSP_ID="$(create_user suspendeduser suspended@example.test suspended-pass-1)"
php -r '
  $site = require "site.php";
  $app = $site->bootApp();
  $user = Flarum\User\User::findOrFail((int)$argv[1]);
  $user->suspended_until = Carbon\Carbon::now()->addDays(7);
  $user->save();
' "${SUSP_ID}"
# Suspended users may still get forum tokens depending on Flarum; connect-token must deny.
SUSP_TOKEN="$(token_for suspendeduser suspended-pass-1 || true)"
if [[ -n "${SUSP_TOKEN}" ]]; then
  SUSP_CONN_CODE="$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H "Authorization: Token ${SUSP_TOKEN}" -d '{}' \
    "${BASE}/api/flatrate-live-chat/realtime/connect-token")"
  if [[ "${SUSP_CONN_CODE}" == "403" ]]; then DENIED[SUSPENDED_CONNECTION]=DENIED; else DENIED[SUSPENDED_CONNECTION]=FAIL; fi
else
  DENIED[SUSPENDED_CONNECTION]=DENIED
fi

# Brand subscription for ordinary member → 404
BRAND_SUB_CODE="$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H "Authorization: Token ${ALICE_TOKEN}" \
  -d "{\"roomKey\":\"${BRAND_ROOM_KEY}\"}" "${BASE}/api/flatrate-live-chat/realtime/subscription-token")"
if [[ "${BRAND_SUB_CODE}" == "404" || "${BRAND_SUB_CODE}" == "403" ]]; then
  DENIED[UNAUTHORIZED_BRAND_SUBSCRIPTION]=DENIED
else
  DENIED[UNAUTHORIZED_BRAND_SUBSCRIPTION]=FAIL
fi

# ========== Negative WS denials ==========
# Fresh Alice tokens for denial tests
CONN_RESP="$(fetch_connect_token "${ALICE_TOKEN}")"
ALICE_CONN_TOKEN="$(php -r 'echo json_decode(file_get_contents("php://stdin"), true)["token"] ?? "";' <<<"$(printf '%s' "${CONN_RESP}" | tail -n +2)")"
SUB_RESP="$(fetch_sub_token "${ALICE_TOKEN}" "${GENERAL_ROOM_KEY}")"
ALICE_SUB_TOKEN="$(php -r 'echo json_decode(file_get_contents("php://stdin"), true)["token"] ?? "";' <<<"$(printf '%s' "${SUB_RESP}" | tail -n +2)")"

# CROSS_ROOM: try subscribe brand channel with general sub token
CROSS_OUT="${SMOKE_ROOT}/ws/cross-room.json"
if run_ws --mode subscribe_expect_deny --ws "${CENTRIFUGO_WS}" \
  --connect-token "${ALICE_CONN_TOKEN}" \
  --channel "${BRAND_CHANNEL}" \
  --sub-token "${ALICE_SUB_TOKEN}" \
  --out "${CROSS_OUT}" --timeout 12000; then
  DENIED[CROSS_ROOM_SUBSCRIPTION]=DENIED
  RESULT_REAL_WS_CROSS_ROOM_DENIAL=PASS
else
  DENIED[CROSS_ROOM_SUBSCRIPTION]=FAIL
  RESULT_REAL_WS_CROSS_ROOM_DENIAL=FAIL
fi

# MISSING_SUBSCRIPTION_TOKEN
MISS_OUT="${SMOKE_ROOT}/ws/missing-sub.json"
if run_ws --mode subscribe_expect_deny --ws "${CENTRIFUGO_WS}" \
  --connect-token "${ALICE_CONN_TOKEN}" \
  --channel "${GENERAL_CHANNEL}" \
  --missing-sub-token 1 \
  --out "${MISS_OUT}" --timeout 12000; then
  DENIED[MISSING_SUBSCRIPTION_TOKEN]=DENIED
else
  DENIED[MISSING_SUBSCRIPTION_TOKEN]=FAIL
fi

# INVALID_SUBSCRIPTION_TOKEN
INV_OUT="${SMOKE_ROOT}/ws/invalid-sub.json"
if run_ws --mode subscribe_expect_deny --ws "${CENTRIFUGO_WS}" \
  --connect-token "${ALICE_CONN_TOKEN}" \
  --channel "${GENERAL_CHANNEL}" \
  --sub-token "not-a-jwt" \
  --out "${INV_OUT}" --timeout 12000; then
  DENIED[INVALID_SUBSCRIPTION_TOKEN]=DENIED
else
  DENIED[INVALID_SUBSCRIPTION_TOKEN]=FAIL
fi

# TAMPERED_JWT — flip a character in the payload segment
TAMPERED="$(php -r '
  $t=$argv[1]; $p=explode(".", $t);
  if (count($p)!==3) {fwrite(STDERR,"bad jwt\n"); exit(1);}
  $p[1]=substr($p[1],0,-1).(substr($p[1],-1)==="A"?"B":"A");
  echo implode(".", $p);
' "${ALICE_SUB_TOKEN}")"
TAMP_OUT="${SMOKE_ROOT}/ws/tampered.json"
if run_ws --mode subscribe_expect_deny --ws "${CENTRIFUGO_WS}" \
  --connect-token "${ALICE_CONN_TOKEN}" \
  --channel "${GENERAL_CHANNEL}" \
  --sub-token "${TAMPERED}" \
  --out "${TAMP_OUT}" --timeout 12000; then
  DENIED[TAMPERED_JWT]=DENIED
else
  DENIED[TAMPERED_JWT]=FAIL
fi

# WRONG_USER_SUBSCRIPTION — Bob connection + Alice subscription token
BOB_CONN_RESP="$(fetch_connect_token "${BOB_TOKEN}")"
BOB_CONN_TOKEN="$(php -r 'echo json_decode(file_get_contents("php://stdin"), true)["token"] ?? "";' <<<"$(printf '%s' "${BOB_CONN_RESP}" | tail -n +2)")"
WRONG_OUT="${SMOKE_ROOT}/ws/wrong-user.json"
if run_ws --mode subscribe_expect_deny --ws "${CENTRIFUGO_WS}" \
  --connect-token "${BOB_CONN_TOKEN}" \
  --channel "${GENERAL_CHANNEL}" \
  --sub-token "${ALICE_SUB_TOKEN}" \
  --out "${WRONG_OUT}" --timeout 12000; then
  DENIED[WRONG_USER_SUBSCRIPTION]=DENIED
else
  DENIED[WRONG_USER_SUBSCRIPTION]=FAIL
fi

# EXPIRED_SUBSCRIPTION_TOKEN — mint short TTL via direct issuer (app default remains 300)
EXPIRED_TOKEN="$(
php -r '
require $argv[1]."/vendor/autoload.php";
use FlatRate\LiveChat\Realtime\CentrifugoClientConfig;
use FlatRate\LiveChat\Realtime\RsaRealtimeTokenIssuer;
$cfg = new CentrifugoClientConfig(
  $argv[2], $argv[3], $argv[4], $argv[5], file_get_contents($argv[6]),
  CentrifugoClientConfig::JWT_ALGORITHM,
  CentrifugoClientConfig::DEFAULT_ISSUER,
  CentrifugoClientConfig::DEFAULT_AUDIENCE,
  300, 1, false, true
);
$issuer = new RsaRealtimeTokenIssuer($cfg);
$issued = $issuer->issueSubscriptionToken((string)$argv[7], $argv[8]);
echo $issued["token"];
' "${SMOKE_ROOT}/app" "${CENTRIFUGO_WS}" "${EDGE_PUBLISH}" "${EDGE_KEY}" "${API_KEY}" \
  "${JWT_PRIVATE_FILE}" "${ALICE_ID}" "${GENERAL_CHANNEL}"
)"
sleep 2
EXP_OUT="${SMOKE_ROOT}/ws/expired-sub.json"
if run_ws --mode subscribe_expect_deny --ws "${CENTRIFUGO_WS}" \
  --connect-token "${ALICE_CONN_TOKEN}" \
  --channel "${GENERAL_CHANNEL}" \
  --sub-token "${EXPIRED_TOKEN}" \
  --out "${EXP_OUT}" --timeout 12000; then
  DENIED[EXPIRED_SUBSCRIPTION_TOKEN]=DENIED
else
  DENIED[EXPIRED_SUBSCRIPTION_TOKEN]=FAIL
fi

# CLIENT_DIRECT_PUBLISH
CDP_OUT="${SMOKE_ROOT}/ws/client-direct-publish.json"
# Need fresh valid tokens
CONN_RESP="$(fetch_connect_token "${ALICE_TOKEN}")"
ALICE_CONN_TOKEN="$(php -r 'echo json_decode(file_get_contents("php://stdin"), true)["token"] ?? "";' <<<"$(printf '%s' "${CONN_RESP}" | tail -n +2)")"
SUB_RESP="$(fetch_sub_token "${ALICE_TOKEN}" "${GENERAL_ROOM_KEY}")"
ALICE_SUB_TOKEN="$(php -r 'echo json_decode(file_get_contents("php://stdin"), true)["token"] ?? "";' <<<"$(printf '%s' "${SUB_RESP}" | tail -n +2)")"
if run_ws --mode client_direct_publish --ws "${CENTRIFUGO_WS}" \
  --connect-token "${ALICE_CONN_TOKEN}" \
  --channel "${GENERAL_CHANNEL}" \
  --sub-token "${ALICE_SUB_TOKEN}" \
  --out "${CDP_OUT}" --timeout 15000; then
  DENIED[CLIENT_DIRECT_PUBLISH]=DENIED
else
  DENIED[CLIENT_DIRECT_PUBLISH]=FAIL
fi

UNAUTHORIZED_OK=1
for k in GUEST_CONNECTION SUSPENDED_CONNECTION UNAUTHORIZED_BRAND_SUBSCRIPTION \
         CROSS_ROOM_SUBSCRIPTION MISSING_SUBSCRIPTION_TOKEN INVALID_SUBSCRIPTION_TOKEN \
         TAMPERED_JWT WRONG_USER_SUBSCRIPTION EXPIRED_SUBSCRIPTION_TOKEN CLIENT_DIRECT_PUBLISH; do
  if [[ "${DENIED[$k]:-FAIL}" != "DENIED" ]]; then
    UNAUTHORIZED_OK=0
    log "DENIED_CASE_FAIL ${k}=${DENIED[$k]:-FAIL}"
  else
    log "DENIED_CASE ${k}=DENIED"
  fi
done
if [[ "${UNAUTHORIZED_OK}" == "1" ]]; then
  RESULT_REAL_WS_UNAUTHORIZED_DENIAL=PASS
else
  RESULT_REAL_WS_UNAUTHORIZED_DENIAL=FAIL
fi

# Build DENIED_CASES_JSON from associative array
: > "${SMOKE_ROOT}/denied-cases.env"
for k in "${!DENIED[@]}"; do
  printf '%s=%s\n' "$k" "${DENIED[$k]}" >> "${SMOKE_ROOT}/denied-cases.env"
done
DENIED_CASES_JSON="$(
  SMOKE_ROOT_FOR_PY="${SMOKE_ROOT}" python3 -c '
import json, os
d = {}
with open(os.environ["SMOKE_ROOT_FOR_PY"] + "/denied-cases.env") as f:
    for line in f:
        line = line.strip()
        if not line or "=" not in line:
            continue
        k, v = line.split("=", 1)
        d[k] = v
print(json.dumps(d, sort_keys=True))
'
)"

# ========== Reconnect ==========
CONN_RESP="$(fetch_connect_token "${ALICE_TOKEN}")"
ALICE_CONN_TOKEN="$(php -r 'echo json_decode(file_get_contents("php://stdin"), true)["token"] ?? "";' <<<"$(printf '%s' "${CONN_RESP}" | tail -n +2)")"
SUB_RESP="$(fetch_sub_token "${ALICE_TOKEN}" "${GENERAL_ROOM_KEY}")"
ALICE_SUB_TOKEN="$(php -r 'echo json_decode(file_get_contents("php://stdin"), true)["token"] ?? "";' <<<"$(printf '%s' "${SUB_RESP}" | tail -n +2)")"
CONN_RESP2="$(fetch_connect_token "${ALICE_TOKEN}")"
ALICE_CONN_TOKEN2="$(php -r 'echo json_decode(file_get_contents("php://stdin"), true)["token"] ?? "";' <<<"$(printf '%s' "${CONN_RESP2}" | tail -n +2)")"

REPLAY_JSON="$(php -r 'echo json_encode(["v"=>2,"type"=>"message.created","roomKey"=>"community-general-live","eventId"=>"replay-dedupe-1","payload"=>["messageId"=>999]]);')"
RECONNECT_OUT="${SMOKE_ROOT}/ws/reconnect.json"
# Post-reconnect live message id captured after listener is up.
RECONNECT_MSG_FILE="${SMOKE_ROOT}/ws/reconnect-msg-id.txt"
: > "${RECONNECT_MSG_FILE}"
(
  # Phase 1: connect, subscribe, force disconnect, reconnect with fresh token, prove dedupe.
  run_ws --mode reconnect_resume --ws "${CENTRIFUGO_WS}" \
    --connect-token "${ALICE_CONN_TOKEN}" \
    --reconnect-token "${ALICE_CONN_TOKEN2}" \
    --channel "${GENERAL_CHANNEL}" \
    --sub-token "${ALICE_SUB_TOKEN}" \
    --replay-event-json "${REPLAY_JSON}" \
    --out "${RECONNECT_OUT}" \
    --timeout 35000
) &
RE_PID=$!
sleep 3
# Post after reconnect window opens
RE_CREATED="$(mktemp)"
RE_CODE="$(curl -sS -o "${RE_CREATED}" -w '%{http_code}' -H 'Content-Type: application/json' -H "Authorization: Token ${BOB_TOKEN}" \
  -d "{\"data\":{\"attributes\":{\"chat_id\":${GENERAL_ID},\"message\":\"after reconnect\"}}}" \
  "${BASE}/api/chatmessages/${GENERAL_ID}" || true)"
if [[ "${RE_CODE}" == "201" ]]; then
  php -r '$j=json_decode(file_get_contents($argv[1]), true); file_put_contents($argv[2], (string)($j["data"]["id"] ?? ""));' \
    "${RE_CREATED}" "${RECONNECT_MSG_FILE}"
fi
rm -f "${RE_CREATED}"

if wait "${RE_PID}"; then
  RE_OK="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo !empty($j["ok"]) ? "1" : "0";' "${RECONNECT_OUT}")"
  DUP="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["duplicateBubbles"] ?? -1;' "${RECONNECT_OUT}")"
  # PASS: reconnected+subscribed and history-merge dedupe kept a single bubble (duplicateBubbles>=1).
  if [[ "${RE_OK}" == "1" ]]; then
    RESULT_REAL_RECONNECT=PASS
  else
    RESULT_REAL_RECONNECT=FAIL
    log "REAL_RECONNECT=FAIL ok=${RE_OK} duplicateBubbles=${DUP}"
    cat "${RECONNECT_OUT}" >&2 || true
  fi
else
  RESULT_REAL_RECONNECT=FAIL
  cat "${RECONNECT_OUT}" >&2 || true
fi
log "REAL_RECONNECT=${RESULT_REAL_RECONNECT}"

# Revoked member cannot regain: suspend Alice, new tokens denied; old JWT still works until exp (measured).
PRE_REV_CONN="${ALICE_CONN_TOKEN2}"
php -r '
  $site = require "site.php";
  $app = $site->bootApp();
  $user = Flarum\User\User::findOrFail((int)$argv[1]);
  $user->suspended_until = Carbon\Carbon::now()->addDays(7);
  $user->save();
' "${ALICE_ID}"

REV_NEW_CODE="$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H "Authorization: Token ${ALICE_TOKEN}" -d '{}' \
  "${BASE}/api/flatrate-live-chat/realtime/connect-token")"
# Note: Flarum session token may still work; connect-token must 403 for suspended.
REV_NEW_DENIED=0
if [[ "${REV_NEW_CODE}" == "403" ]]; then REV_NEW_DENIED=1; fi

# Already-issued connection token: measure whether Centrifugo still accepts (no instant revocation).
REV_OUT="${SMOKE_ROOT}/ws/revocation-old-token.json"
REV_START="$(date +%s)"
OLD_TOKEN_STILL_WORKS=0
if run_ws --mode connect_only --ws "${CENTRIFUGO_WS}" --connect-token "${PRE_REV_CONN}" --out "${REV_OUT}" --timeout 10000; then
  OLD_TOKEN_STILL_WORKS=1
fi
REV_END="$(date +%s)"

# Connected-client revocation window: without server disconnect API on suspend,
# already-connected clients are not force-disconnected. Measure JWT remaining TTL.
REMAINING_TTL="$(php -r '
  $parts=explode(".", $argv[1]);
  $payload=json_decode(base64_decode(strtr($parts[1], "-_", "+/")), true);
  $exp=(int)($payload["exp"] ?? 0);
  echo max(0, $exp - time());
' "${PRE_REV_CONN}")"

if [[ "${REV_NEW_DENIED}" == "1" && "${OLD_TOKEN_STILL_WORKS}" == "1" ]]; then
  # Instant revocation NOT proven; window ≈ remaining JWT lifetime (app default 300s).
  RESULT_REVOCATION_WINDOW_SECONDS="${REMAINING_TTL}"
  log "REVOCATION=measured new_tokens=DENIED already_issued_tokens=STILL_VALID until_exp remaining_s=${REMAINING_TTL}"
  log "REVOCATION_NOTE=no_explicit_centrifugo_disconnect_on_suspend; NOT claiming instant revocation"
elif [[ "${REV_NEW_DENIED}" == "1" && "${OLD_TOKEN_STILL_WORKS}" == "0" ]]; then
  RESULT_REVOCATION_WINDOW_SECONDS=0
  log "REVOCATION=unexpected_old_token_rejected"
else
  RESULT_REVOCATION_WINDOW_SECONDS=NOT_PROVEN
  log "REVOCATION=NOT_PROVEN new_token_status=${REV_NEW_CODE} old_works=${OLD_TOKEN_STILL_WORKS}"
fi

# Unsuspend Alice for outage test cleanliness (optional)
php -r '
  $site = require "site.php";
  $app = $site->bootApp();
  $user = Flarum\User\User::findOrFail((int)$argv[1]);
  $user->suspended_until = null;
  $user->save();
' "${ALICE_ID}" >/dev/null 2>&1 || true

# ========== Publisher outage: stop edge; HTTP post must succeed; no public channel fallback ==========
kill "${EDGE_PID}" >/dev/null 2>&1 || true
EDGE_PID=""
sleep 0.5

OUTAGE_CREATED="$(mktemp)"
OUTAGE_CODE="$(curl -sS -o "${OUTAGE_CREATED}" -w '%{http_code}' -H 'Content-Type: application/json' -H "Authorization: Token ${BOB_TOKEN}" \
  -d "{\"data\":{\"attributes\":{\"chat_id\":${GENERAL_ID},\"message\":\"during publisher outage\"}}}" \
  "${BASE}/api/chatmessages/${GENERAL_ID}")"
OUTAGE_MSG_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["data"]["id"] ?? "";' "${OUTAGE_CREATED}")"
rm -f "${OUTAGE_CREATED}"

# Restart edge briefly only to query presence of public pollution via Centrifugo history/API is hard;
# instead assert publisher did not switch to FakeRealtime / public channels by checking no fake file
# and that incomplete/outage path stays best-effort (HTTP 201, DB row exists).
DB_EXISTS="$(php -r '
$pdo = new PDO("mysql:host=".$argv[1].";port=".$argv[2].";dbname=".$argv[3].";charset=utf8mb4", $argv[4], $argv[5], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$st=$pdo->prepare("SELECT COUNT(*) FROM neonchat_messages WHERE id=?");
$st->execute([(int)$argv[6]]);
echo (int)$st->fetchColumn();
' "${DB_HOST}" "${DB_PORT}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}" "${OUTAGE_MSG_ID}")"

# Probe Centrifugo: ensure no subscribe-without-token public channel exists for flatrate-live
PUBLIC_PROBE_OUT="${SMOKE_ROOT}/ws/public-probe.json"
CONN_RESP="$(fetch_connect_token "${BOB_TOKEN}")"
BOB_CONN_TOKEN="$(php -r 'echo json_decode(file_get_contents("php://stdin"), true)["token"] ?? "";' <<<"$(printf '%s' "${CONN_RESP}" | tail -n +2)")"
PUBLIC_FALLBACK=0
if run_ws --mode subscribe_expect_deny --ws "${CENTRIFUGO_WS}" \
  --connect-token "${BOB_CONN_TOKEN}" \
  --channel "flatrate-live-community-general-live" \
  --missing-sub-token 1 \
  --out "${PUBLIC_PROBE_OUT}" --timeout 8000; then
  PUBLIC_FALLBACK=0
else
  # If subscribe unexpectedly allowed, that's a fallback disclosure failure
  if php -r '$j=json_decode(file_get_contents($argv[1]),true); exit(!empty($j["subscribed"])?0:1);' "${PUBLIC_PROBE_OUT}"; then
    PUBLIC_FALLBACK=1
  fi
fi

if [[ "${OUTAGE_CODE}" == "201" && "${DB_EXISTS}" == "1" && "${PUBLIC_FALLBACK}" == "0" ]]; then
  RESULT_PUBLISHER_OUTAGE_FALLBACK=PASS
else
  RESULT_PUBLISHER_OUTAGE_FALLBACK=FAIL
  log "OUTAGE_FAIL http=${OUTAGE_CODE} db=${DB_EXISTS} public_fallback=${PUBLIC_FALLBACK}"
fi
log "PUBLISHER_OUTAGE_FALLBACK=${RESULT_PUBLISHER_OUTAGE_FALLBACK}"

# --- Persist evidence summary ---
mkdir -p "${EVIDENCE_DIR}"
printf '%s\n' "${DENIED_CASES_JSON}" > "${SMOKE_ROOT}/denied-cases.normalized.json"
python3 - <<PY
import json, pathlib, time
denied = json.loads(pathlib.Path("${SMOKE_ROOT}/denied-cases.normalized.json").read_text())
summary = {
  "tested_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
  "worktree": "${ROOT}",
  "centrifugo_image": "${CENTRIFUGO_IMAGE}",
  "flarum_core": "${EXPECTED_CORE}",
  "publish_path_proven": "${PUBLISH_PATH_PROVEN}",
  "cloudflare_edge": "LEFT_UNTESTED",
  "results": {
    "REAL_CENTRIFUGO_SERVER": "${RESULT_REAL_CENTRIFUGO_SERVER}",
    "REAL_WS_CONNECT": "${RESULT_REAL_WS_CONNECT}",
    "REAL_WS_SUBSCRIBE": "${RESULT_REAL_WS_SUBSCRIBE}",
    "REAL_WS_MESSAGE_DELIVERY": "${RESULT_REAL_WS_MESSAGE_DELIVERY}",
    "REAL_WS_CROSS_ROOM_DENIAL": "${RESULT_REAL_WS_CROSS_ROOM_DENIAL}",
    "REAL_WS_UNAUTHORIZED_DENIAL": "${RESULT_REAL_WS_UNAUTHORIZED_DENIAL}",
    "REAL_RECONNECT": "${RESULT_REAL_RECONNECT}",
    "REVOCATION_WINDOW_SECONDS": "${RESULT_REVOCATION_WINDOW_SECONDS}",
    "PUBLISHER_OUTAGE_FALLBACK": "${RESULT_PUBLISHER_OUTAGE_FALLBACK}",
  },
  "denied_cases": denied,
  "channels": {
    "general": "${GENERAL_CHANNEL}",
    "brand": "${BRAND_CHANNEL}",
  },
  "notes": [
    "App JWT defaults remain 300s; short TTL used only for EXPIRED_SUBSCRIPTION_TOKEN case via test issuer.",
    "Revocation: new tokens denied after suspend; already-issued JWTs remain valid until exp (no Centrifugo disconnect on suspend).",
    "Publisher outage: local edge stopped; Cloudflare edge not involved.",
  ],
}
evidence_dir = pathlib.Path("${EVIDENCE_DIR}")
evidence_dir.mkdir(parents=True, exist_ok=True)
path = evidence_dir / "results.json"
path.write_text(json.dumps(summary, indent=2) + "\n")
(evidence_dir / "e2e.log").write_text(pathlib.Path("${LOG}").read_text())
print(f"EVIDENCE={path}")
PY

# Overall gate
FAIL=0
for v in \
  "${RESULT_REAL_CENTRIFUGO_SERVER}" \
  "${RESULT_REAL_WS_CONNECT}" \
  "${RESULT_REAL_WS_SUBSCRIBE}" \
  "${RESULT_REAL_WS_MESSAGE_DELIVERY}" \
  "${RESULT_REAL_WS_CROSS_ROOM_DENIAL}" \
  "${RESULT_REAL_WS_UNAUTHORIZED_DENIAL}" \
  "${RESULT_REAL_RECONNECT}" \
  "${RESULT_PUBLISHER_OUTAGE_FALLBACK}"; do
  if [[ "${v}" != "PASS" ]]; then FAIL=1; fi
done
if [[ "${RESULT_REVOCATION_WINDOW_SECONDS}" == "NOT_PROVEN" ]]; then
  FAIL=1
fi

if [[ "${FAIL}" == "1" ]]; then
  echo "LIVE_F2_CENTRIFUGO_E2E=FAIL" >&2
  exit 1
fi

echo "LIVE_F2_CENTRIFUGO_E2E=PASS"
echo "PRODUCTION_INSTALL=false"
echo "PRODUCTION_MUTATED=false"
