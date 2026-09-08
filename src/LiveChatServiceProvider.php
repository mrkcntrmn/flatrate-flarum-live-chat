<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Provisioner\RoomProvisioner;
use FlatRate\LiveChat\Realtime\FakeRealtimePublisher;
use FlatRate\LiveChat\Realtime\NullRealtimePublisher;
use FlatRate\LiveChat\Realtime\PerRoomChannelNamer;
use FlatRate\LiveChat\Realtime\PusherChannelAuthorizer;
use FlatRate\LiveChat\Realtime\PusherClientConfig;
use FlatRate\LiveChat\Realtime\PusherRealtimePublisher;
use FlatRate\LiveChat\Realtime\RealtimePublisher;
use FlatRate\LiveChat\Rollout\RolloutApplicator;
use FlatRate\LiveChat\Rollout\RolloutProfile;
use FlatRate\LiveChat\Observability\RealtimeLogger;
use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Contracts\Container\Container;

class LiveChatServiceProvider extends AbstractServiceProvider
{
    public function register()
    {
        $this->container->singleton(ChatAuthorization::class);
        $this->container->singleton(RoomCatalog::class);
        $this->container->singleton(RolloutProfile::class);
        $this->container->singleton(RealtimeLogger::class);
        $this->container->singleton(PusherClientConfig::class, function () {
            return PusherClientConfig::fromEnvironment();
        });
        $this->container->singleton(PerRoomChannelNamer::class);
        $this->container->singleton(PusherChannelAuthorizer::class);

        $this->container->singleton(RealtimePublisher::class, function (Container $container) {
            $fake = getenv('FLATRATE_LIVE_CHAT_FAKE_REALTIME');
            if ($fake === false || $fake === '') {
                $fake = $_SERVER['FLATRATE_LIVE_CHAT_FAKE_REALTIME'] ?? $_ENV['FLATRATE_LIVE_CHAT_FAKE_REALTIME'] ?? '';
            }
            if ($fake === '1' || $fake === 'true') {
                return new FakeRealtimePublisher();
            }

            $config = $container->make(PusherClientConfig::class);
            if ($config->isComplete()) {
                return new PusherRealtimePublisher($config, $container->make(RealtimeLogger::class));
            }

            // Fail closed: no publish when credentials incomplete.
            return new NullRealtimePublisher();
        });

        $this->container->bind(RoomProvisioner::class, function (Container $container) {
            $allow = getenv('FLATRATE_LIVE_CHAT_ALLOW_WRITES');
            if ($allow === false || $allow === '') {
                $allow = $_SERVER['FLATRATE_LIVE_CHAT_ALLOW_WRITES'] ?? $_ENV['FLATRATE_LIVE_CHAT_ALLOW_WRITES'] ?? '';
            }
            $allowWrites = ($allow === '1' || $allow === 'true');
            return new RoomProvisioner(
                $container->make(RoomCatalog::class),
                $allowWrites,
                $container->make(RolloutProfile::class)
            );
        });

        $this->container->bind(RolloutApplicator::class, function (Container $container) {
            $allow = getenv('FLATRATE_LIVE_CHAT_ALLOW_WRITES');
            if ($allow === false || $allow === '') {
                $allow = $_SERVER['FLATRATE_LIVE_CHAT_ALLOW_WRITES'] ?? $_ENV['FLATRATE_LIVE_CHAT_ALLOW_WRITES'] ?? '';
            }
            $allowWrites = ($allow === '1' || $allow === 'true');
            return new RolloutApplicator(
                $container->make(RoomCatalog::class),
                $container->make(RolloutProfile::class),
                $allowWrites
            );
        });
    }
}
