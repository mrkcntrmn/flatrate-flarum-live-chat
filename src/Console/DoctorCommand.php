<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Console;

use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Realtime\PusherClientConfig;
use FlatRate\LiveChat\Rollout\RolloutProfile;
use Flarum\Console\AbstractCommand;

/**
 * php flarum flatrate:live-chat:doctor
 * Reports catalog/rollout/transport without secrets.
 */
class DoctorCommand extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('flatrate:live-chat:doctor')
            ->setDescription('Report FlatRate live-chat catalog, rollout, and transport status (no secrets)');
    }

    protected function fire()
    {
        $catalog = resolve(RoomCatalog::class);
        $rollout = resolve(RolloutProfile::class);
        $config = resolve(PusherClientConfig::class);

        $rollout->assertValid();
        $expanded = $rollout->expandForRooms($catalog->rooms());
        $memberVisible = count(array_filter(
            $expanded,
            fn ($r) => $r['visibility'] === 'visible' && $r['audience'] === 'members'
        ));
        $staffPreview = count(array_filter(
            $expanded,
            fn ($r) => $r['audience'] === 'staff-preview'
        ));

        $diag = $config->diagnostic();

        $lines = [
            'FLATRATE_LIVE_CHAT_DOCTOR=ok',
            'CANONICAL_ROOM_COUNT=' . $catalog->totalRoomCount(),
            'BRAND_ROOM_COUNT=' . $catalog->brandRoomCount(),
            'GENERAL_ROOM_COUNT=' . $catalog->generalRoomCount(),
            'ROLLOUT_PROFILE=' . $rollout->profileId(),
            'MEMBER_VISIBLE_ROOMS=' . $memberVisible,
            'STAFF_PREVIEW_ROOMS=' . $staffPreview,
            'TRANSPORT_DECISION=' . $diag['transportDecision'],
            'TRANSPORT_IMPLEMENTATION_STATUS=' . $diag['transportImplementationStatus'],
            'TRANSPORT_EXTERNAL_QUALIFICATION=' . $diag['transportExternalQualification'],
            'PRODUCTION_PUSHER_CONFIGURED=false',
            'PUSHER_SELECTED=false',
            'CREDENTIALS_COMPLETE=' . ($diag['credentialsComplete'] ? 'true' : 'false'),
            'HAS_KEY=' . ($diag['hasKey'] ? 'true' : 'false'),
            'HAS_SECRET=' . ($diag['hasSecret'] ? 'true' : 'false'),
            'HAS_APP_ID=' . ($diag['hasAppId'] ? 'true' : 'false'),
            'CHAT_ATTACHMENTS_INITIAL=false',
            'CHAT_INDEXING=false',
            'CHAT_EMAIL_NOTIFICATIONS=false',
            'DM_COUPLING=false',
        ];

        foreach ($lines as $line) {
            $this->info($line);
        }
    }
}
