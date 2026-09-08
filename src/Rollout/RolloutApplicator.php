<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Rollout;

use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Chat;

/**
 * Apply visibility/audience without rewriting roomKey/id/scope/messages.
 */
class RolloutApplicator
{
    public function __construct(
        private RoomCatalog $catalog,
        private RolloutProfile $profile,
        private bool $allowWrites = false
    ) {
    }

    /**
     * @param list<array{id?:int|string,room_key?:string,roomKey?:string,visibility?:string,audience?:string}> $existingRooms
     * @return array{profile:string,expected:int,memberVisible:int,staffPreview:int,updated:list,unchanged:int,writesApplied:bool}
     */
    public function apply(array $existingRooms = [], bool $dryRun = true): array
    {
        $this->profile->assertValid();
        $expected = $this->catalog->rooms();
        $byKey = [];
        foreach ($existingRooms as $row) {
            $key = $row['room_key'] ?? $row['roomKey'] ?? null;
            if ($key) {
                $byKey[$key] = $row;
            }
        }

        $updated = [];
        $unchanged = 0;
        $memberVisible = 0;
        $staffPreview = 0;

        foreach ($expected as $room) {
            $key = $room['roomKey'];
            $policy = $this->profile->policyForRoomKey($key);
            if ($policy['visibility'] === RoomVisibility::VISIBLE && $policy['audience'] === RoomAudience::MEMBERS) {
                $memberVisible++;
            }
            if ($policy['audience'] === RoomAudience::STAFF_PREVIEW) {
                $staffPreview++;
            }

            if (!isset($byKey[$key])) {
                continue;
            }
            $existing = $byKey[$key];
            $curVis = $existing['visibility'] ?? null;
            $curAud = $existing['audience'] ?? null;
            if ($curVis === $policy['visibility'] && $curAud === $policy['audience']) {
                $unchanged++;
                continue;
            }

            $entry = [
                'roomKey' => $key,
                'from' => ['visibility' => $curVis, 'audience' => $curAud],
                'to' => $policy,
                'identityPreserved' => true,
            ];

            if (!$dryRun) {
                if (!$this->allowWrites) {
                    throw new \RuntimeException('rollout apply blocked: allowWrites=false');
                }
                $id = $existing['id'] ?? null;
                if ($id) {
                    $chat = Chat::query()->find($id);
                    if ($chat) {
                        // Visibility transition must NOT change roomKey/id/scope/messages.
                        $chat->visibility = $policy['visibility'];
                        $chat->audience = $policy['audience'];
                        $chat->save();
                        $entry['applied'] = true;
                    }
                }
            }
            $updated[] = $entry;
        }

        return [
            'profile' => $this->profile->profileId(),
            'expected' => count($expected),
            'memberVisible' => $memberVisible,
            'staffPreview' => $staffPreview,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'writesApplied' => !$dryRun,
        ];
    }
}
