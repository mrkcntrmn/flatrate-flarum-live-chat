<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Provisioner;

use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Rollout\RolloutProfile;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;

/**
 * Deterministic catalog/state fingerprints for production reconcile binding.
 */
final class RoomReconcileSnapshot
{
    public const CLASS_READY_INITIAL_CREATE = 'READY_INITIAL_CREATE';
    public const CLASS_ALREADY_RECONCILED = 'ALREADY_RECONCILED';
    public const CLASS_REVIEW_REQUIRED = 'REVIEW_REQUIRED';

    public const CONFIRM_PREFIX = 'FORUM-CHAT-001D:RECONCILE:42:general-live-first:';

    public function __construct(
        private RoomCatalog $catalog,
        private RolloutProfile $rollout
    ) {
    }

    public function catalogSha256(): string
    {
        $rooms = $this->catalog->rooms();
        usort($rooms, static function (array $a, array $b): int {
            return strcmp((string) ($a['roomKey'] ?? ''), (string) ($b['roomKey'] ?? ''));
        });
        $normalized = [];
        foreach ($rooms as $room) {
            $normalized[] = [
                'roomKey' => (string) ($room['roomKey'] ?? ''),
                'name' => (string) ($room['name'] ?? ''),
                'scopeType' => (string) ($room['scopeType'] ?? ''),
                'scopeKey' => (string) ($room['scopeKey'] ?? ''),
            ];
        }
        $payload = json_encode([
            'profileId' => $this->rollout->profileId(),
            'rooms' => $normalized,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new \RuntimeException('catalog fingerprint encode failed');
        }

        return hash('sha256', $payload);
    }

    /**
     * @param list<array<string,mixed>> $keyedRows
     */
    public function stateSha256(string $catalogSha256, array $keyedRows): string
    {
        $rows = $this->normalizeKeyedRows($keyedRows);
        $payload = json_encode([
            'catalogSha256' => $catalogSha256,
            'rolloutProfile' => $this->rollout->profileId(),
            'rooms' => $rows,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new \RuntimeException('state fingerprint encode failed');
        }

        return hash('sha256', $payload);
    }

    /**
     * @param list<array<string,mixed>> $keyedRows
     * @return list<array{id:string,type:int,title:string,room_key:string,scope_type:string,scope_key:string,visibility:string,audience:string}>
     */
    public function normalizeKeyedRows(array $keyedRows): array
    {
        $out = [];
        foreach ($keyedRows as $row) {
            $key = (string) ($row['room_key'] ?? $row['roomKey'] ?? '');
            if ($key === '') {
                continue;
            }
            $out[] = [
                'id' => (string) ($row['id'] ?? ''),
                'type' => (int) ($row['type'] ?? 1),
                'title' => (string) ($row['title'] ?? $row['name'] ?? ''),
                'room_key' => $key,
                'scope_type' => (string) ($row['scope_type'] ?? $row['scopeType'] ?? ''),
                'scope_key' => (string) ($row['scope_key'] ?? $row['scopeKey'] ?? ''),
                'visibility' => (string) ($row['visibility'] ?? ''),
                'audience' => (string) ($row['audience'] ?? ''),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['room_key'], $b['room_key']));

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $keyedRows
     * @return array{
     *   ok:bool,
     *   catalogCount:int,
     *   existingCanonicalCount:int,
     *   missingCount:int,
     *   extraCount:int,
     *   driftedCount:int,
     *   memberVisibleExpected:int,
     *   staffPreviewExpected:int,
     *   rolloutProfile:string,
     *   catalogSha256:string,
     *   stateSha256:string,
     *   classification:string
     * }
     */
    public function analyze(array $keyedRows): array
    {
        $this->rollout->assertValid();
        $catalogCount = count($this->catalog->rooms());
        if ($catalogCount !== 42
            || $this->catalog->generalRoomCount() !== 1
            || $this->catalog->brandRoomCount() !== 41
            || $this->rollout->profileId() !== RolloutProfile::PROFILE_GENERAL_LIVE_FIRST
        ) {
            throw new \RuntimeException('embedded catalog/profile invariants failed');
        }

        $provisioner = new RoomProvisioner($this->catalog, false, $this->rollout);
        $diff = $provisioner->run(RoomProvisioner::MODE_DRY_RUN, $keyedRows);

        $existingCanonical = 0;
        $catalogKeys = [];
        foreach ($this->catalog->rooms() as $room) {
            $catalogKeys[(string) $room['roomKey']] = true;
        }
        foreach ($keyedRows as $row) {
            $key = (string) ($row['room_key'] ?? $row['roomKey'] ?? '');
            if ($key !== '' && isset($catalogKeys[$key])) {
                $existingCanonical++;
            }
        }

        $missing = count($diff['missing']);
        $extra = count($diff['extra']);
        $drifted = count($diff['drifted']);

        $classification = self::CLASS_REVIEW_REQUIRED;
        if ($existingCanonical === 0 && $missing === 42 && $extra === 0 && $drifted === 0) {
            $classification = self::CLASS_READY_INITIAL_CREATE;
        } elseif ($existingCanonical === 42 && $missing === 0 && $extra === 0 && $drifted === 0) {
            $classification = self::CLASS_ALREADY_RECONCILED;
        }

        $catalogSha = $this->catalogSha256();
        $stateSha = $this->stateSha256($catalogSha, $keyedRows);

        return [
            'ok' => true,
            'catalogCount' => 42,
            'existingCanonicalCount' => $existingCanonical,
            'missingCount' => $missing,
            'extraCount' => $extra,
            'driftedCount' => $drifted,
            'memberVisibleExpected' => 1,
            'staffPreviewExpected' => 41,
            'rolloutProfile' => RolloutProfile::PROFILE_GENERAL_LIVE_FIRST,
            'catalogSha256' => $catalogSha,
            'stateSha256' => $stateSha,
            'classification' => $classification,
        ];
    }

    public function expectedConfirmation(string $stateSha256): string
    {
        return self::CONFIRM_PREFIX . $stateSha256;
    }

    /**
     * @param list<array<string,mixed>> $keyedRows
     */
    public function assertPostconditionRows(array $keyedRows): void
    {
        $analysis = $this->analyze($keyedRows);
        if ($analysis['classification'] !== self::CLASS_ALREADY_RECONCILED) {
            throw new \RuntimeException('postcondition failed: ' . $analysis['classification']);
        }

        $general = null;
        $brandHidden = 0;
        foreach ($keyedRows as $row) {
            $key = (string) ($row['room_key'] ?? $row['roomKey'] ?? '');
            $vis = (string) ($row['visibility'] ?? '');
            $aud = (string) ($row['audience'] ?? '');
            $type = (int) ($row['type'] ?? 0);
            if ($type !== 1 || $key === '') {
                throw new \RuntimeException('postcondition identity failed');
            }
            if ($key === 'community-general-live') {
                $general = [$vis, $aud];
            } elseif ($vis === RoomVisibility::HIDDEN && $aud === RoomAudience::STAFF_PREVIEW) {
                $brandHidden++;
            }
        }
        if ($general !== [RoomVisibility::VISIBLE, RoomAudience::MEMBERS]) {
            throw new \RuntimeException('general room postcondition failed');
        }
        if ($brandHidden !== 41) {
            throw new \RuntimeException('brand room postcondition failed');
        }
    }
}
