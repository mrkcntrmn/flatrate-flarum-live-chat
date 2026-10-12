<?php
/*
 * FORUM-LIVE-ADMIN-001 R1 — read-only authoritative room telemetry.
 * Admin identity is Flarum-owned. No changes to message, room or user state.
 */

namespace FlatRate\LiveChat\Api\Controllers;

use Carbon\Carbon;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Auth\GeneralLiveGate;
use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\Message;
use FlatRate\LiveChat\Realtime\CentrifugoChannelNamer;
use FlatRate\LiveChat\Realtime\CentrifugoPresenceStatsReader;
use FlatRate\LiveChat\Rollout\RolloutProfile;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AdminDashboardController implements RequestHandlerInterface
{
    public const GATE = 'flatrate-live-chat.admin_dashboard_enabled';

    public function __construct(
        private ChatAuthorization $auth,
        private SettingsRepositoryInterface $settings,
        private RoomCatalog $catalog,
        private RolloutProfile $rollout,
        private CentrifugoChannelNamer $channels,
        private CentrifugoPresenceStatsReader $presence
    ) {
    }

    public static function gateEnabled($value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $headers = [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow, nosnippet',
            'X-Content-Type-Options' => 'nosniff',
        ];

        // Always authorize before looking up any rooms, settings, or presence.
        try {
            $this->auth->assertAdmin(RequestUtil::getActor($request));
        } catch (PermissionDeniedException $e) {
            return new JsonResponse(['error' => 'permission_denied'], 403, $headers);
        }

        if (!self::gateEnabled($this->settings->get(self::GATE))) {
            return new JsonResponse(['error' => 'dashboard_disabled'], 404, $headers);
        }

        $query = $request->getQueryParams();
        if (array_diff(array_keys($query), ['presence_room'])) {
            return new JsonResponse(['error' => 'invalid_parameters'], 400, $headers);
        }
        $selected = $query['presence_room'] ?? null;
        if ($selected !== null && (!is_string($selected) || $selected === '')) {
            return new JsonResponse(['error' => 'invalid_parameters'], 400, $headers);
        }

        $rooms = $this->catalog->rooms();
        $roomKeys = array_column($rooms, 'roomKey');
        if ($selected !== null && !in_array($selected, $roomKeys, true)) {
            // Never let caller provide a realtime channel or arbitrary room key.
            return new JsonResponse(['error' => 'not_found'], 404, $headers);
        }

        // 46 canonical room records, one query. No general Live member-directory filter.
        $stored = Chat::query()->whereIn('room_key', $roomKeys)->get([
            'id', 'type', 'title', 'room_key', 'scope_type', 'scope_key', 'visibility', 'audience',
        ])->groupBy('room_key');

        $ids = [];
        foreach ($stored as $matches) {
            foreach ($matches as $row) {
                if ((int) $row->type === 1) {
                    $ids[] = (int) $row->id;
                }
            }
        }

        // Aggregate from canonical, non-deleted human messages; no message bodies
        // or user IDs are returned. All-time last activity remains a DB aggregate.
        $stats = [];
        if ($ids) {
            $now = Carbon::now('UTC');
            $oneHour = $now->copy()->subHour()->toDateTimeString();
            $oneDay = $now->copy()->subDay()->toDateTimeString();
            $oneWeek = $now->copy()->subWeek()->toDateTimeString();
            $rows = Message::query()
                ->whereIn('chat_id', $ids)
                ->where('type', 0)
                ->whereNull('deleted_by')
                ->selectRaw('chat_id, MAX(created_at) AS last_activity_at')
                ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS messages_1h', [$oneHour])
                ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS messages_24h', [$oneDay])
                ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS messages_7d', [$oneWeek])
                ->selectRaw('COUNT(DISTINCT CASE WHEN created_at >= ? THEN user_id END) AS authors_24h', [$oneDay])
                ->groupBy('chat_id')
                ->get();
            foreach ($rows as $row) {
                $stats[(int) $row->chat_id] = [
                    'messages1h' => (int) $row->messages_1h,
                    'messages24h' => (int) $row->messages_24h,
                    'messages7d' => (int) $row->messages_7d,
                    'authors24h' => (int) $row->authors_24h,
                    'lastActivityAt' => $row->last_activity_at === null ? null : (string) $row->last_activity_at,
                ];
            }
        }

        $items = [];
        $presentCount = 0;
        $driftedCount = 0;
        $missingCount = 0;
        $messages24h = 0;
        foreach ($rooms as $canonical) {
            $key = (string) $canonical['roomKey'];
            $matches = $stored->get($key);
            $actual = $matches && $matches->count() === 1 ? $matches->first() : null;
            $policy = $this->rollout->policyForRoomKey($key);
            $found = $actual !== null;
            $drift = $matches && $matches->count() > 1;
            if ($found) {
                $presentCount++;
                $drift = $drift
                    || (int) $actual->type !== 1
                    || (string) $actual->title !== (string) $canonical['name']
                    || (string) $actual->scope_type !== (string) $canonical['scopeType']
                    || (string) $actual->scope_key !== (string) $canonical['scopeKey']
                    || (string) $actual->visibility !== $policy['visibility']
                    || (string) $actual->audience !== $policy['audience'];
            } elseif (!$drift) {
                $missingCount++;
            }
            if ($drift) {
                $driftedCount++;
            }
            $metrics = $found ? ($stats[(int) $actual->id] ?? []) : [];
            $messages24h += (int) ($metrics['messages24h'] ?? 0);
            $items[] = [
                'roomKey' => $key,
                'title' => (string) $canonical['name'],
                'scopeType' => (string) $canonical['scopeType'],
                'scopeKey' => (string) $canonical['scopeKey'],
                'state' => $drift ? 'drifted' : ($found ? 'present' : 'missing'),
                'visibility' => $found ? (string) $actual->visibility : null,
                'audience' => $found ? (string) $actual->audience : null,
                'expectedVisibility' => $policy['visibility'],
                'expectedAudience' => $policy['audience'],
                'messages1h' => (int) ($metrics['messages1h'] ?? 0),
                'messages24h' => (int) ($metrics['messages24h'] ?? 0),
                'messages7d' => (int) ($metrics['messages7d'] ?? 0),
                'authors24h' => (int) ($metrics['authors24h'] ?? 0),
                'lastActivityAt' => $metrics['lastActivityAt'] ?? null,
            ];
        }

        // One count-only on-demand sample, never a 46-request polling fan-out.
        $presence = null;
        if ($selected !== null) {
            $matching = $stored->get($selected);
            $actual = $matching && $matching->count() === 1 ? $matching->first() : null;
            $count = null;
            if ($actual && (int) $actual->type === 1) {
                $count = $this->presence->uniqueUsers($this->channels->channelForRoomKey($selected));
            }
            $presence = [
                'roomKey' => $selected,
                'liveUserCount' => $count,
                'available' => $count !== null,
                'sampledAt' => gmdate('c'),
            ];
        }

        return new JsonResponse([
            'schemaVersion' => 1,
            'sampledAt' => gmdate('c'),
            'readOnly' => true,
            'expected' => count($rooms),
            'present' => $presentCount,
            'missing' => $missingCount,
            'drifted' => $driftedCount,
            'messages24h' => $messages24h,
            'rollout' => [
                'generalLiveOperational' => GeneralLiveGate::isEnabled(
                    $this->settings->get(GeneralLiveGate::SETTING_KEY)
                ),
                'adminPreview' => self::gateEnabled(
                    $this->settings->get('flatrate-live-chat.general_live_admin_preview_enabled')
                ),
                'memberBeta' => self::gateEnabled(
                    $this->settings->get('flatrate-live-chat.member_beta_enabled')
                ),
                'userLive' => self::gateEnabled(
                    $this->settings->get('flatrate-live-chat.general_live_user_enabled')
                ),
            ],
            'rooms' => $items,
            'selectedPresence' => $presence,
            'limitations' => [
                'onlineCounts' => 'on-demand count-only; not aggregated into totals',
                'historicalErrors' => 'not_instrumented',
                'messageLatency' => 'not_instrumented',
            ],
        ], 200, $headers);
    }
}
