<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Provisioner;

use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\Rollout\RolloutProfile;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;

/**
 * validate / dry-run / reconcile for canonical rooms.
 * Disposable only — defaults to dry-run. No production credentials.
 * Reconcile always targets CANONICAL_ROOM_COUNT=42.
 */
class RoomProvisioner
{
    public const MODE_VALIDATE = 'validate';
    public const MODE_DRY_RUN = 'dry-run';
    public const MODE_RECONCILE = 'reconcile';

    public function __construct(
        private RoomCatalog $catalog,
        private bool $allowWrites = false,
        private ?RolloutProfile $rollout = null
    ) {
        $this->rollout = $rollout ?? new RolloutProfile();
    }

    /**
     * @param list<array{room_key?:string,roomKey?:string,title?:string,scope_type?:string,scope_key?:string,visibility?:string,audience?:string}> $existingRooms
     * @return array{mode:string,expected:int,missing:list,extra:list,drifted:list,created:list,updated:list,unchanged:int,writesApplied:bool}
     */
    public function run(string $mode, array $existingRooms = []): array
    {
        $mode = $mode === self::MODE_RECONCILE ? self::MODE_RECONCILE : (
            $mode === self::MODE_VALIDATE ? self::MODE_VALIDATE : self::MODE_DRY_RUN
        );

        if ($mode === self::MODE_RECONCILE && !$this->allowWrites) {
            throw new \RuntimeException('RECONCILE blocked: DRY_RUN_ONLY without production credentials (allowWrites=false)');
        }

        $expected = $this->catalog->rooms();
        if (count($expected) !== 42) {
            throw new \RuntimeException('CANONICAL_ROOM_COUNT must be 42');
        }

        $byKey = [];
        foreach ($existingRooms as $row) {
            $key = $row['room_key'] ?? $row['roomKey'] ?? null;
            if ($key) {
                $byKey[$key] = $row;
            }
        }

        $missing = [];
        $drifted = [];
        $created = [];
        $updated = [];
        $seen = [];

        foreach ($expected as $room) {
            $key = $room['roomKey'];
            $seen[$key] = true;
            $policy = $this->rollout->policyForRoomKey($key);

            if (!isset($byKey[$key])) {
                $missing[] = $room;
                if ($mode === self::MODE_RECONCILE) {
                    $created[] = $this->createRoom($room, $policy);
                }
                continue;
            }
            $existing = $byKey[$key];
            $title = $existing['title'] ?? $existing['name'] ?? null;
            $scopeType = $existing['scope_type'] ?? $existing['scopeType'] ?? null;
            $scopeKey = $existing['scope_key'] ?? $existing['scopeKey'] ?? null;
            $drift = [];
            if ($title !== $room['name']) {
                $drift['name'] = ['from' => $title, 'to' => $room['name']];
            }
            if ($scopeType !== $room['scopeType'] || $scopeKey !== $room['scopeKey']) {
                $drift['scope'] = [
                    'from' => [$scopeType, $scopeKey],
                    'to' => [$room['scopeType'], $room['scopeKey']],
                ];
            }
            $curVis = $existing['visibility'] ?? null;
            $curAud = $existing['audience'] ?? null;
            if ($curVis !== $policy['visibility'] || $curAud !== $policy['audience']) {
                $drift['rollout'] = [
                    'from' => ['visibility' => $curVis, 'audience' => $curAud],
                    'to' => $policy,
                ];
            }
            if ($drift) {
                $drifted[] = ['roomKey' => $key, 'drift' => $drift];
                if ($mode === self::MODE_RECONCILE) {
                    $updated[] = $this->updateRoom($existing, $room, $policy);
                }
            }
        }

        $extra = [];
        foreach ($byKey as $key => $row) {
            if (!isset($seen[$key]) && !empty($row['room_key'] ?? $row['roomKey'])) {
                $extra[] = ['roomKey' => $key];
            }
        }

        return [
            'mode' => $mode === self::MODE_RECONCILE ? self::MODE_RECONCILE : ($mode === self::MODE_VALIDATE ? self::MODE_VALIDATE : self::MODE_DRY_RUN),
            'expected' => count($expected),
            'missing' => $missing,
            'extra' => $extra,
            'drifted' => $drifted,
            'created' => $created,
            'updated' => $updated,
            'unchanged' => count($expected) - count($missing) - count($drifted),
            'writesApplied' => $mode === self::MODE_RECONCILE,
            'rolloutProfile' => $this->rollout->profileId(),
        ];
    }

    private function createRoom(array $room, array $policy): array
    {
        $chat = Chat::build(
            $room['name'],
            '#334155',
            '',
            1,
            0,
            \Carbon\Carbon::now()
        );
        $chat->room_key = $room['roomKey'];
        $chat->scope_type = $room['scopeType'];
        $chat->scope_key = $room['scopeKey'];
        $chat->visibility = $policy['visibility'] ?? RoomVisibility::HIDDEN;
        $chat->audience = $policy['audience'] ?? RoomAudience::STAFF_PREVIEW;
        $chat->save();
        return [
            'roomKey' => $room['roomKey'],
            'id' => $chat->id,
            'visibility' => $chat->visibility,
            'audience' => $chat->audience,
        ];
    }

    private function updateRoom(array $existing, array $room, array $policy): array
    {
        $id = $existing['id'] ?? null;
        if (!$id) {
            return ['roomKey' => $room['roomKey'], 'updated' => false];
        }
        $chat = Chat::query()->find($id);
        if (!$chat) {
            return ['roomKey' => $room['roomKey'], 'updated' => false];
        }
        // Display name may update; room_key / scope are durable identity — never rewrite.
        $chat->title = $room['name'];
        // Visibility/audience may transition without changing identity/messages.
        $chat->visibility = $policy['visibility'];
        $chat->audience = $policy['audience'];
        $chat->save();
        return [
            'roomKey' => $room['roomKey'],
            'updated' => true,
            'visibility' => $chat->visibility,
            'audience' => $chat->audience,
            'identityPreserved' => true,
        ];
    }
}
