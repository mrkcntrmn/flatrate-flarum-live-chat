<?php

namespace FlatRate\LiveChat\Admin;

use FlatRate\LiveChat\Auth\GeneralLiveGate;
use FlatRate\LiveChat\Auth\GeneralLiveMainRollout;
use FlatRate\LiveChat\Auth\MemberBetaGate;
use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\Message;
use FlatRate\LiveChat\Provisioner\RoomReconcileSnapshot;
use FlatRate\LiveChat\Realtime\CentrifugoChannelNamer;
use FlatRate\LiveChat\Realtime\CentrifugoPresenceStatsReader;
use FlatRate\LiveChat\Rollout\RolloutProfile;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Read-only, admin-safe operational projection for the Live Chat dashboard.
 *
 * The payload intentionally contains no message body, participant identity,
 * credential, arbitrary channel name, or mutating capability.
 */
final class AdminDashboardService
{
    public const ENABLED_SETTING_KEY = 'flatrate-live-chat.admin_dashboard_enabled';

    /** @var callable(): list<array<string,mixed>> */
    private $loadRooms;

    /** @var callable(list<int>, int): list<array<string,mixed>> */
    private $loadMetrics;

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private RoomCatalog $catalog,
        private RolloutProfile $rollout,
        private RoomReconcileSnapshot $reconcile,
        private SettingsRepositoryInterface $settings,
        private CentrifugoChannelNamer $channels,
        private CentrifugoPresenceStatsReader $presence,
        ?callable $loadRooms = null,
        ?callable $loadMetrics = null,
        ?callable $clock = null
    ) {
        $this->loadRooms = $loadRooms ?? [$this, 'defaultLoadRooms'];
        $this->loadMetrics = $loadMetrics ?? [$this, 'defaultLoadMetrics'];
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function enabled(): bool
    {
        return GeneralLiveMainRollout::isOptInEnabled(
            $this->settings->get(self::ENABLED_SETTING_KEY)
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(?string $presenceRoomKey = null): array
    {
        $now = (int) ($this->clock)();
        $sampledAt = gmdate('c', $now);
        $catalogRooms = $this->catalog->rooms();
        $storedRows = ($this->loadRooms)();
        $reconcile = $this->reconcile->analyze($storedRows);

        $storedByKey = [];
        $chatIds = [];
        foreach ($storedRows as $row) {
            $key = (string) ($row['room_key'] ?? $row['roomKey'] ?? '');
            if ($key === '') {
                continue;
            }
            $storedByKey[$key] = $row;
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $chatIds[] = $id;
            }
        }

        $metricsByChatId = [];
        foreach (($this->loadMetrics)($chatIds, $now) as $metrics) {
            $id = (int) ($metrics['chat_id'] ?? 0);
            if ($id > 0) {
                $metricsByChatId[$id] = $metrics;
            }
        }

        $presenceRoom = $this->resolvePresenceRoom($presenceRoomKey);
        $gates = $this->gates();
        $rooms = [];
        $totals = [
            'messages1h' => 0,
            'messages24h' => 0,
            'messages7d' => 0,
            'eventMessages24h' => 0,
            'roomsWithActivity24h' => 0,
        ];

        foreach ($catalogRooms as $catalogRoom) {
            $key = (string) ($catalogRoom['roomKey'] ?? '');
            $stored = $storedByKey[$key] ?? null;
            $chatId = (int) ($stored['id'] ?? 0);
            $metrics = $metricsByChatId[$chatId] ?? [];
            $policy = $this->rollout->policyForRoomKey($key);
            $state = $stored === null ? 'missing' : ($this->isDrifted($catalogRoom, $policy, $stored) ? 'drifted' : 'present');
            $isGeneral = GeneralLiveGate::isGeneralLiveRoomKey($key);

            $room = [
                'roomKey' => $key,
                'name' => (string) ($catalogRoom['name'] ?? $key),
                'scopeType' => (string) ($catalogRoom['scopeType'] ?? ''),
                'scopeKey' => (string) ($catalogRoom['scopeKey'] ?? ''),
                'catalogState' => $state,
                'chatId' => $chatId > 0 ? $chatId : null,
                'visibility' => $stored !== null ? (string) ($stored['visibility'] ?? '') : $policy['visibility'],
                'audience' => $stored !== null ? (string) ($stored['audience'] ?? '') : $policy['audience'],
                'effectiveAvailability' => $this->availability($isGeneral, $gates),
                'messages1h' => (int) ($metrics['messages_1h'] ?? 0),
                'messages24h' => (int) ($metrics['messages_24h'] ?? 0),
                'messages7d' => (int) ($metrics['messages_7d'] ?? 0),
                'eventMessages24h' => (int) ($metrics['event_messages_24h'] ?? 0),
                'uniquePosters24h' => (int) ($metrics['unique_posters_24h'] ?? 0),
                'lastActivityAt' => $this->nullableDate($metrics['last_activity_at'] ?? null),
                'presence' => null,
            ];

            if ($presenceRoom !== null && $presenceRoom === $key && $stored !== null) {
                $count = $this->presence->uniqueUsers($this->channels->channelForRoomKey($key));
                $room['presence'] = [
                    'available' => $count !== null,
                    'uniqueUsers' => $count,
                    'sampledAt' => $sampledAt,
                ];
            }

            $totals['messages1h'] += $room['messages1h'];
            $totals['messages24h'] += $room['messages24h'];
            $totals['messages7d'] += $room['messages7d'];
            $totals['eventMessages24h'] += $room['eventMessages24h'];
            if ($room['messages24h'] > 0) {
                $totals['roomsWithActivity24h']++;
            }
            $rooms[] = $room;
        }

        return [
            'ok' => true,
            'schemaVersion' => 1,
            'sampledAt' => $sampledAt,
            'freshnessSeconds' => 60,
            'health' => $this->health($reconcile),
            'reconciliation' => $reconcile,
            'gates' => $gates,
            'totals' => $totals,
            'rooms' => $rooms,
        ];
    }

    /** @return array<string,bool> */
    private function gates(): array
    {
        return [
            'dashboardEnabled' => $this->enabled(),
            'generalLiveEnabled' => GeneralLiveGate::isEnabled(
                $this->settings->get(GeneralLiveGate::SETTING_KEY)
            ),
            'adminPreviewEnabled' => GeneralLiveMainRollout::isOptInEnabled(
                $this->settings->get(GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY)
            ),
            'userLiveEnabled' => GeneralLiveMainRollout::isOptInEnabled(
                $this->settings->get(GeneralLiveMainRollout::USER_LIVE_SETTING_KEY)
            ),
            'memberBetaEnabled' => MemberBetaGate::isEnabledValue(
                $this->settings->get(MemberBetaGate::SETTING_KEY)
            ),
        ];
    }

    /**
     * @param array<string,bool> $gates
     * @return array<string,bool>
     */
    private function availability(bool $isGeneral, array $gates): array
    {
        if ($isGeneral) {
            return [
                'adminPreview' => $gates['generalLiveEnabled'] && $gates['adminPreviewEnabled'],
                'approvedMemberBeta' => $gates['generalLiveEnabled'] && $gates['memberBetaEnabled'],
                'broadMember' => $gates['generalLiveEnabled'] && $gates['userLiveEnabled'],
            ];
        }

        return [
            'adminPreview' => $gates['adminPreviewEnabled'],
            'approvedMemberBeta' => $gates['memberBetaEnabled'],
            'broadMember' => false,
        ];
    }

    /**
     * @param array<string,mixed> $catalogRoom
     * @param array{visibility:string,audience:string} $policy
     * @param array<string,mixed> $stored
     */
    private function isDrifted(array $catalogRoom, array $policy, array $stored): bool
    {
        return (int) ($stored['type'] ?? 0) !== 1
            || (string) ($stored['title'] ?? '') !== (string) ($catalogRoom['name'] ?? '')
            || (string) ($stored['scope_type'] ?? '') !== (string) ($catalogRoom['scopeType'] ?? '')
            || (string) ($stored['scope_key'] ?? '') !== (string) ($catalogRoom['scopeKey'] ?? '')
            || (string) ($stored['visibility'] ?? '') !== $policy['visibility']
            || (string) ($stored['audience'] ?? '') !== $policy['audience'];
    }

    private function resolvePresenceRoom(?string $roomKey): ?string
    {
        if ($roomKey === null || $roomKey === '') {
            return null;
        }
        if ($this->catalog->findByRoomKey($roomKey) === null) {
            throw new \InvalidArgumentException('unknown room');
        }

        return $roomKey;
    }

    /** @param mixed $value */
    private function nullableDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : gmdate('c', $timestamp);
    }

    /** @return list<array<string,mixed>> */
    public function defaultLoadRooms(): array
    {
        return Chat::query()
            ->whereNotNull('room_key')
            ->get(['id', 'type', 'title', 'room_key', 'scope_type', 'scope_key', 'visibility', 'audience'])
            ->map(static fn (Chat $chat): array => $chat->getAttributes())
            ->all();
    }

    /**
     * Catalog reconciliation only. This is not a realtime transport SLO.
     *
     * @param array<string,mixed> $reconcile
     * @return array<string,string>
     */
    private function health(array $reconcile): array
    {
        $catalogOk = ($reconcile['missingCount'] ?? 1) === 0
            && ($reconcile['extraCount'] ?? 1) === 0
            && ($reconcile['driftedCount'] ?? 1) === 0;

        return [
            'status' => $catalogOk ? 'catalog_reconciled' : 'catalog_attention',
            'catalog' => $catalogOk ? 'reconciled' : 'attention',
            'presenceMode' => 'selected-room-on-demand',
            'historicalRealtimeMetrics' => 'not_instrumented',
        ];
    }

    /**
     * Rolling counts use created_at bounds. Lifetime last activity is
     * MAX(created_at) among non-deleted rows. Message ids are not a time order:
     * callers may supply created_at, so an id floor can drop an in-window row.
     *
     * @param list<int> $chatIds
     * @return list<array<string,mixed>>
     */
    public function defaultLoadMetrics(array $chatIds, int $now): array
    {
        $chatIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $chatIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($chatIds === []) {
            return [];
        }

        $bounds = DashboardMetricCalculator::bounds($now);
        $window = $this->loadWindowMetrics($chatIds, $bounds);
        $activity = $this->loadLifetimeLastActivity($chatIds);
        $merged = [];
        foreach ($window as $row) {
            $id = (int) ($row['chat_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $row['last_activity_at'] = $activity[$id] ?? null;
            $merged[$id] = $row;
        }
        foreach ($activity as $id => $createdAt) {
            if (isset($merged[$id]) || $createdAt === null) {
                continue;
            }
            $merged[$id] = [
                'chat_id' => $id,
                'messages_1h' => 0,
                'messages_24h' => 0,
                'messages_7d' => 0,
                'event_messages_24h' => 0,
                'unique_posters_24h' => 0,
                'last_activity_at' => $createdAt,
            ];
        }

        return array_values($merged);
    }

    /**
     * @param list<int> $chatIds
     * @param array{hour:string,day:string,week:string} $bounds
     * @return list<array<string,mixed>>
     */
    private function loadWindowMetrics(array $chatIds, array $bounds): array
    {
        $query = Message::query()
            ->select('chat_id')
            ->selectRaw('SUM(CASE WHEN type = 0 AND created_at >= ? THEN 1 ELSE 0 END) AS messages_1h', [$bounds['hour']])
            ->selectRaw('SUM(CASE WHEN type = 0 AND created_at >= ? THEN 1 ELSE 0 END) AS messages_24h', [$bounds['day']])
            ->selectRaw('SUM(CASE WHEN type = 0 AND created_at >= ? THEN 1 ELSE 0 END) AS messages_7d', [$bounds['week']])
            ->selectRaw('SUM(CASE WHEN type <> 0 AND created_at >= ? THEN 1 ELSE 0 END) AS event_messages_24h', [$bounds['day']])
            ->selectRaw('COUNT(DISTINCT CASE WHEN type = 0 AND created_at >= ? THEN user_id END) AS unique_posters_24h', [$bounds['day']])
            ->whereNull('deleted_by')
            ->where('created_at', '>=', $bounds['week'])
            ->whereIn('chat_id', $chatIds)
            ->groupBy('chat_id');

        return $query
            ->get()
            ->map(static fn (Message $message): array => $message->getAttributes())
            ->all();
    }

    /**
     * Latest non-deleted timestamp per chat. This is not the highest id.
     *
     * @param list<int> $chatIds
     * @return array<int,?string>
     */
    private function loadLifetimeLastActivity(array $chatIds): array
    {
        $activity = [];
        foreach (array_chunk($chatIds, 40) as $chunk) {
            $rows = Message::query()
                ->select('chat_id')
                ->selectRaw('MAX(created_at) AS last_activity_at')
                ->whereNull('deleted_by')
                ->whereIn('chat_id', $chunk)
                ->groupBy('chat_id')
                ->get();
            foreach ($rows as $row) {
                $id = (int) $row->chat_id;
                if ($id <= 0) {
                    continue;
                }
                $createdAt = $row->last_activity_at;
                if ($createdAt instanceof \DateTimeInterface) {
                    $createdAt = $createdAt->format('Y-m-d H:i:s');
                }
                $activity[$id] = $createdAt === null || $createdAt === ''
                    ? null
                    : (string) $createdAt;
            }
        }

        return $activity;
    }
}
