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
use FlatRate\LiveChat\Realtime\FakeRealtimePublisher;
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
            // Disposable HTTP matrix may opt into FakeRealtimePublisher via env.
            $fake = getenv('FLATRATE_LIVE_CHAT_FAKE_REALTIME');
            if ($fake === false || $fake === '') {
                $fake = $_SERVER['FLATRATE_LIVE_CHAT_FAKE_REALTIME'] ?? $_ENV['FLATRATE_LIVE_CHAT_FAKE_REALTIME'] ?? '';
            }
            if ($fake === '1' || $fake === 'true') {
                return new FakeRealtimePublisher();
            }
            return new NullRealtimePublisher();
        });
        $this->container->bind(RoomProvisioner::class, function (Container $container) {
            $allow = getenv('FLATRATE_LIVE_CHAT_ALLOW_WRITES');
            if ($allow === false || $allow === '') {
                $allow = $_SERVER['FLATRATE_LIVE_CHAT_ALLOW_WRITES'] ?? $_ENV['FLATRATE_LIVE_CHAT_ALLOW_WRITES'] ?? '';
            }
            $allowWrites = ($allow === '1' || $allow === 'true');
            return new RoomProvisioner($container->make(RoomCatalog::class), $allowWrites);
        });
    }
}
