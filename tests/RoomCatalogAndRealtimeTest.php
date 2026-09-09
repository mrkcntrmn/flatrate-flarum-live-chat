<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\Provisioner\RoomProvisioner;
use FlatRate\LiveChat\Realtime\CentrifugoChannelNamer;
use FlatRate\LiveChat\Realtime\FakeRealtimePublisher;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use PHPUnit\Framework\TestCase;

class RoomCatalogAndRealtimeTest extends TestCase
{
    public function testCatalogCounts(): void
    {
        $catalog = new RoomCatalog();
        $this->assertSame(41, $catalog->brandRoomCount());
        $this->assertSame(1, $catalog->generalRoomCount());
        $this->assertSame(42, $catalog->totalRoomCount());
        $this->assertCount(42, $catalog->rooms());
    }

    public function testGmAndCdjrIndependence(): void
    {
        $catalog = new RoomCatalog();
        $keys = array_column($catalog->rooms(), 'roomKey');
        $this->assertContains('gm-live', $keys);
        $this->assertContains('buick-live', $keys);
        $this->assertContains('cadillac-live', $keys);
        $this->assertContains('chevrolet-live', $keys);
        $this->assertContains('gmc-live', $keys);
        $this->assertNotSame(
            $catalog->findByRoomKey('gm-live'),
            $catalog->findByRoomKey('buick-live')
        );
        $this->assertContains('cdjr-live', $keys);
        $this->assertContains('chrysler-live', $keys);
        $this->assertContains('dodge-live', $keys);
        $this->assertContains('jeep-live', $keys);
        $this->assertContains('ram-live', $keys);
    }

    public function testGeneralLiveScope(): void
    {
        $general = (new RoomCatalog())->findByRoomKey('community-general-live');
        $this->assertNotNull($general);
        $this->assertSame('navigation-group', $general['scopeType']);
        $this->assertSame('community', $general['scopeKey']);
        $this->assertNotSame('technician-topics', $general['scopeKey']);
        $this->assertNotSame('general-shop-discussion', $general['scopeKey']);
    }

    public function testProvisionerDryRunExpects42(): void
    {
        $p = new RoomProvisioner(new RoomCatalog(), false);
        $result = $p->run(RoomProvisioner::MODE_DRY_RUN, []);
        $this->assertSame(42, $result['expected']);
        $this->assertCount(42, $result['missing']);
        $this->assertFalse($result['writesApplied']);
    }

    public function testReconcileBlockedWithoutAllowWrites(): void
    {
        $p = new RoomProvisioner(new RoomCatalog(), false);
        $this->expectException(\RuntimeException::class);
        $p->run(RoomProvisioner::MODE_RECONCILE, []);
    }

    public function testBrandRenameKeepsRoomKey(): void
    {
        $catalog = new RoomCatalog();
        $toyota = $catalog->findByRoomKey('toyota-live');
        $this->assertSame('toyota', $toyota['scopeKey']);
        $existing = [[
            'id' => 1,
            'room_key' => 'toyota-live',
            'title' => 'Toyota Motors Live',
            'scope_type' => 'board',
            'scope_key' => 'toyota',
        ]];
        $p = new RoomProvisioner($catalog, false);
        $result = $p->run(RoomProvisioner::MODE_DRY_RUN, $existing);
        $driftKeys = array_column($result['drifted'], 'roomKey');
        $this->assertContains('toyota-live', $driftKeys);
        $this->assertSame('toyota-live', $toyota['roomKey']);
    }

    public function testPerRoomRealtimeIsolationToyotaCannotReceiveGm(): void
    {
        $auth = new ChatAuthorization();
        $namer = new CentrifugoChannelNamer($auth);
        $pub = new FakeRealtimePublisher();

        $toyota = new Chat();
        $toyota->type = 1;
        $toyota->room_key = 'toyota-live';
        $gm = new Chat();
        $gm->type = 1;
        $gm->room_key = 'gm-live';

        $toyotaCh = $namer->channelForRoom($toyota);
        $gmCh = $namer->channelForRoom($gm);
        $this->assertNotSame($toyotaCh, $gmCh);
        $this->assertSame('$flatrate-live-toyota-live', $toyotaCh);
        $this->assertSame('$flatrate-live-gm-live', $gmCh);

        $pub->publish($gmCh, 'message.created', ['room_key' => 'gm-live']);
        $this->assertTrue($pub->hasEvent($gmCh, 'message.created'));
        $this->assertFalse($pub->hasEvent($toyotaCh, 'message.created'));
        $this->assertSame([], $pub->eventsFor($toyotaCh));
    }

    public function testUnauthorizedSubscribeFailsForGuest(): void
    {
        $auth = new ChatAuthorization();
        $namer = new CentrifugoChannelNamer($auth);
        $guest = new User(null);
        $guest->permissions = [ChatAuthorization::PERM_ENABLED => true];
        $chat = new Chat();
        $chat->type = 1;
        $chat->room_key = 'toyota-live';
        $this->expectException(PermissionDeniedException::class);
        $namer->assertCanSubscribe($guest, $chat);
    }

    public function testNoSharedPublicChannelConstant(): void
    {
        $socket = file_get_contents(dirname(__DIR__) . '/src/ChatSocket.php');
        $this->assertStringNotContainsString('function sendPublic', $socket);
        $this->assertStringNotContainsString("trigger('public'", $socket);
        $this->assertStringContainsString('SHARED_PUBLIC_REALTIME_CHANNEL=false', $socket);
    }
}
