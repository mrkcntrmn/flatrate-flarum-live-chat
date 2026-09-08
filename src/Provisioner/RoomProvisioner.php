<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Provisioner;

use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Chat;

/**
 * validate / dry-run / reconcile for canonical rooms.
 * Disposable only — defaults to dry-run. No production credentials.
 */
class RoomProvisioner
{
    public const MODE_VALIDATE = 'validate';
    public const MODE_DRY_RUN = 'dry-run';
    public const MODE_RECONCILE = 'reconcile';

    public function __construct(
        private RoomCatalog $catalog,
        private bool $allowWrites = false
    ) {
    }

    /**
     * @param list<array{room_key?:string,roomKey?:string,title?:string,scope_type?:string,scope_key?:string}> $existingRooms
     * @return array{mode:string,expected:int,missing:list,extra:list,drifted:list,created:list,updated:list,unchanged:int}
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
            if (!isset($byKey[$key])) {
                $missing[] = $room;
                if ($mode === self::MODE_RECONCILE) {
                    $created[] = $this->createRoom($room);
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
            if ($drift) {
                $drifted[] = ['roomKey' => $key, 'drift' => $drift];
                if ($mode === self::MODE_RECONCILE) {
                    $updated[] = $this->updateRoom($existing, $room);
                }
            }
        }

        $extra = [];
        foreach ($byKey as $key => $row) {
            if (!isset($seen[$key]) && !empty($row['room_key'] ?? $row['roomKey'])) {
                // Extra rows that look canonical (have room_key) are reported.
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
        ];
    }

    private function createRoom(array $room): array
    {
        $chat = Chat::build(
            $room['name'],
            '#334155',
            '',
            1, // public channel type only
            null,
            null
        );
        $chat->room_key = $room['roomKey'];
        $chat->scope_type = $room['scopeType'];
        $chat->scope_key = $room['scopeKey'];
        $chat->save();
        return ['roomKey' => $room['roomKey'], 'id' => $chat->id];
    }

    private function updateRoom(array $existing, array $room): array
    {
        $id = $existing['id'] ?? null;
        if (!$id) {
            return ['roomKey' => $room['roomKey'], 'updated' => false];
        }
        $chat = Chat::query()->find($id);
        if (!$chat) {
            return ['roomKey' => $room['roomKey'], 'updated' => false];
        }
        $chat->title = $room['name'];
        // room_key / scope are durable — do not rewrite keys on display-name reconcile
        $chat->save();
        return ['roomKey' => $room['roomKey'], 'updated' => true];
    }
}
