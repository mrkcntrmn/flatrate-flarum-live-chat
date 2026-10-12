<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Api\Controllers\AdminDashboardController;
use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Rollout\RolloutProfile;
use PHPUnit\Framework\TestCase;

/** R1 source gate and permanent catalog boundaries; API actor matrix is a release gate. */
class AdminDashboardContractTest extends TestCase
{
    public function testAdminMonitoringGateDefaultsOffAndRejectsUnexpectedValues(): void
    {
        foreach ([null, '', false, 0, '0', 'on', 'yes', 'no', 'maybe', 2] as $disabled) {
            $this->assertFalse(AdminDashboardController::gateEnabled($disabled));
        }
        foreach (['1', 1, true, 'true'] as $enabled) {
            $this->assertTrue(AdminDashboardController::gateEnabled($enabled));
        }
    }

    public function testCatalogContainsOneGeneralAnd45Brands(): void
    {
        $catalog = new RoomCatalog();
        $this->assertCount(46, $catalog->rooms());
        $this->assertSame(1, $catalog->generalRoomCount());
        $this->assertSame(45, $catalog->brandRoomCount());
        $rollout = new RolloutProfile();
        $rollout->assertValid();
        foreach ($catalog->rooms() as $room) {
            $policy = $rollout->policyForRoomKey($room['roomKey']);
            if ($room['roomKey'] === 'community-general-live') {
                $this->assertSame('visible', $policy['visibility']);
                $this->assertSame('members', $policy['audience']);
            } else {
                $this->assertSame('hidden', $policy['visibility']);
                $this->assertSame('staff-preview', $policy['audience']);
            }
        }
    }

    public function testAdminSnapshotNeverSerializesMessagesOrIdentity(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Api/Controllers/AdminDashboardController.php');
        $this->assertStringContainsString('assertAdmin(RequestUtil::getActor($request))', $source);
        $this->assertStringContainsString("whereNull('deleted_by')", $source);
        $this->assertStringContainsString("where('type', 0)", $source);
        $this->assertStringContainsString("'Cache-Control' => 'private, no-store'", $source);
        $this->assertStringContainsString("'X-Robots-Tag' => 'noindex, nofollow, nosnippet'", $source);
        $this->assertStringNotContainsString('->get()->toArray()', $source);
        $this->assertStringNotContainsString("->select('message'", $source);
        $this->assertStringNotContainsString("'userIds'", $source);
        $this->assertStringNotContainsString("'messageBodies'", $source);
    }

    public function testDashboardIsDefaultOffAndMountedSeparatelyFromReconcile(): void
    {
        $extend = file_get_contents(dirname(__DIR__) . '/extend.php');
        $this->assertStringContainsString("->default('flatrate-live-chat.admin_dashboard_enabled', '0')", $extend);
        $this->assertStringContainsString("->get('/flatrate-live-chat/admin/rooms/dashboard'", $extend);
        $this->assertStringContainsString("->post('/flatrate-live-chat/admin/rooms/reconcile'", $extend);
        $this->assertStringNotContainsString("->default('flatrate-live-chat.general_live_enabled', '0')", $extend);
    }
}
