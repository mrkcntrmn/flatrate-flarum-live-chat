<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Provisioner\RoomProvisioner;
use FlatRate\LiveChat\Realtime\NullRealtimePublisher;
use FlatRate\LiveChat\Realtime\PerRoomChannelNamer;
use FlatRate\LiveChat\Realtime\RealtimePublisher;
use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Contracts\Container\Container;

class LiveChatServiceProvider extends AbstractServiceProvider
{
    public function register()
    {
        $this->container->singleton(ChatAuthorization::class);
        $this->container->singleton(RoomCatalog::class);
        $this->container->singleton(PerRoomChannelNamer::class);
        $this->container->singleton(RealtimePublisher::class, function () {
            // REALTIME_IMPLEMENTATION_DECISION=PENDING — no Pusher selected/configured.
            return new NullRealtimePublisher();
        });
        $this->container->bind(RoomProvisioner::class, function (Container $container) {
            // DRY_RUN_ONLY by default (allowWrites=false).
            return new RoomProvisioner($container->make(RoomCatalog::class), false);
        });
    }
}
