<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Catalog\RoomCatalog;
use PHPUnit\Framework\TestCase;

class F2BackendContractTest extends TestCase
{
    public function testCanonicalCatalogRemainsFortySixRooms(): void
    {
        $catalog = new RoomCatalog();
        $this->assertSame(46, $catalog->totalRoomCount());
        $this->assertSame(1, $catalog->generalRoomCount());
        $this->assertSame(45, $catalog->brandRoomCount());
        $this->assertCount(45, $catalog->canonicalBrandRoomKeys());
    }

    public function testF2EntryKeepsAuthorizationAndDropsRemovedApis(): void
    {
        $extend = (string) file_get_contents(dirname(__DIR__).'/extend.php');
        $f2 = (string) file_get_contents(dirname(__DIR__).'/extend-f2.php');
        $actions = (string) file_get_contents(dirname(__DIR__).'/src/F2/Api/LiveRoomActions.php');

        $this->assertStringContainsString('AbstractShowController::class', $extend);
        $this->assertStringContainsString('extend-f2.php', $extend);
        $this->assertStringNotContainsString('ApiSerializer', $f2);
        $this->assertStringNotContainsString('PushChatEvents', $f2);
        $this->assertStringNotContainsString('Tobscure', $f2);
        $this->assertStringContainsString('listLiveDirectory', $actions);
        $this->assertStringContainsString('ExactRoomLookup', $actions);
        $this->assertStringContainsString('subscription-token', $f2);
        $this->assertStringContainsString("->default('flatrate-live-chat.admin_dashboard_enabled', '0')", $f2);
        $this->assertStringContainsString("->default('flatrate-live-chat.live_chats_navigation_enabled', '0')", $f2);
        $this->assertStringContainsString('noindex, nofollow', $f2);
        $this->assertStringNotContainsString("js(__DIR__", $f2);
    }
}
