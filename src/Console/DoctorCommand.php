<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Console;

use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Realtime\CentrifugoClientConfig;
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
        $config = resolve(CentrifugoClientConfig::class);

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
            'PRODUCTION_CENTRIFUGO_CONFIGURED=false',
            'CREDENTIALS_COMPLETE=' . ($diag['credentialsComplete'] ? 'true' : 'false'),
            'HAS_WEBSOCKET_URL=' . ($diag['hasWebsocketUrl'] ? 'true' : 'false'),
            'HAS_PUBLISH_URL=' . ($diag['hasPublishUrl'] ? 'true' : 'false'),
            'HAS_EDGE_KEY=' . ($diag['hasEdgeKey'] ? 'true' : 'false'),
            'HAS_API_KEY=' . ($diag['hasApiKey'] ? 'true' : 'false'),
            'HAS_JWT_PRIVATE_KEY=' . ($diag['hasJwtPrivateKey'] ? 'true' : 'false'),
            'URLS_TLS_SAFE=' . ($diag['urlsTlsSafe'] ? 'true' : 'false'),
            'JWT_ALGORITHM=' . $diag['jwtAlgorithm'],
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
