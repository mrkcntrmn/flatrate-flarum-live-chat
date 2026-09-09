<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Console;

use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Realtime\CentrifugoClientConfig;
use FlatRate\LiveChat\Rollout\RolloutProfile;

class DoctorReport
{
    public function __construct(
        private ?RoomCatalog $catalog = null,
        private ?RolloutProfile $rollout = null,
        private ?CentrifugoClientConfig $config = null
    ) {
        $this->catalog = $catalog ?? new RoomCatalog();
        $this->rollout = $rollout ?? new RolloutProfile();
        $this->config = $config ?? CentrifugoClientConfig::fromEnvironment();
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $this->rollout->assertValid();
        $expanded = $this->rollout->expandForRooms($this->catalog->rooms());
        $diag = $this->config->diagnostic();
        return [
            'canonicalRoomCount' => $this->catalog->totalRoomCount(),
            'brandRoomCount' => $this->catalog->brandRoomCount(),
            'generalRoomCount' => $this->catalog->generalRoomCount(),
            'rolloutProfile' => $this->rollout->profileId(),
            'memberVisibleRooms' => count(array_filter(
                $expanded,
                fn ($r) => $r['visibility'] === 'visible' && $r['audience'] === 'members'
            )),
            'staffPreviewRooms' => count(array_filter(
                $expanded,
                fn ($r) => $r['audience'] === 'staff-preview'
            )),
            'transport' => $diag,
            'productionCentrifugoConfigured' => false,
            'NEXT_VERSION' => '1.1.0',
        ];
    }
}
