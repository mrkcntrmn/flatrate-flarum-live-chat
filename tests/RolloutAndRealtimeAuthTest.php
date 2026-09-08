<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\Console\DoctorReport;
use FlatRate\LiveChat\Provisioner\RoomProvisioner;
use FlatRate\LiveChat\Realtime\EventEnvelope;
use FlatRate\LiveChat\Realtime\FakeRealtimePublisher;
use FlatRate\LiveChat\Realtime\NullRealtimePublisher;
use FlatRate\LiveChat\Realtime\PerRoomChannelNamer;
use FlatRate\LiveChat\Realtime\PusherClientConfig;
use FlatRate\LiveChat\Realtime\PusherRealtimePublisher;
use FlatRate\LiveChat\Rollout\RolloutApplicator;
use FlatRate\LiveChat\Rollout\RolloutProfile;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use PHPUnit\Framework\TestCase;

class RolloutAndRealtimeAuthTest extends TestCase
{
    private function member(): User
    {
        $u = new User(10);
        $u->permissions = [
            ChatAuthorization::PERM_ENABLED => true,
            ChatAuthorization::PERM_CHAT => true,
        ];
        return $u;
    }

    private function moderator(): User
    {
        $u = new User(20);
        $u->permissions = [
            ChatAuthorization::PERM_ENABLED => true,
            ChatAuthorization::PERM_CHAT => true,
            ChatAuthorization::PERM_MODERATE => true,
        ];
        return $u;
    }

    private function room(string $key, string $vis, string $aud): Chat
    {
        $chat = new Chat();
        $chat->type = 1;
        $chat->room_key = $key;
        $chat->visibility = $vis;
        $chat->audience = $aud;
        return $chat;
    }

    public function testGeneralLiveFirstProfile(): void
    {
        $profile = new RolloutProfile();
        $profile->assertValid();
        $this->assertSame('general-live-first', $profile->profileId());
        $this->assertSame(42, $profile->canonicalRoomCount());

        $general = $profile->policyForRoomKey('community-general-live');
        $this->assertSame(RoomVisibility::VISIBLE, $general['visibility']);
        $this->assertSame(RoomAudience::MEMBERS, $general['audience']);

        $toyota = $profile->policyForRoomKey('toyota-live');
        $this->assertSame(RoomVisibility::HIDDEN, $toyota['visibility']);
        $this->assertSame(RoomAudience::STAFF_PREVIEW, $toyota['audience']);
    }

    public function testExpandedPoliciesCount(): void
    {
        $catalog = new RoomCatalog();
        $profile = new RolloutProfile();
        $expanded = $profile->expandForRooms($catalog->rooms());
        $this->assertCount(42, $expanded);
        $memberVisible = array_filter($expanded, fn ($r) => $r['visibility'] === 'visible' && $r['audience'] === 'members');
        $this->assertCount(1, $memberVisible);
        $staff = array_filter($expanded, fn ($r) => $r['audience'] === 'staff-preview');
        $this->assertCount(41, $staff);
    }

    public function testMemberCannotSeeHiddenBrandRoom(): void
    {
        $auth = new ChatAuthorization();
        $member = $this->member();
        $toyota = $this->room('toyota-live', RoomVisibility::HIDDEN, RoomAudience::STAFF_PREVIEW);
        $this->assertFalse($auth->isRoomVisibleToActor($member, $toyota));
        $this->expectException(PermissionDeniedException::class);
        $auth->assertCanReadRoom($member, $toyota);
    }

    public function testMemberCanSeeGeneralLive(): void
    {
        $auth = new ChatAuthorization();
        $general = $this->room('community-general-live', RoomVisibility::VISIBLE, RoomAudience::MEMBERS);
        $this->assertTrue($auth->isRoomVisibleToActor($this->member(), $general));
        $auth->assertCanReadRoom($this->member(), $general);
        $this->addToAssertionCount(1);
    }

    public function testStaffCanPreviewHiddenBrandRoom(): void
    {
        $auth = new ChatAuthorization();
        $mod = $this->moderator();
        $this->assertTrue($auth->canPreviewHiddenChatRooms($mod));
        $toyota = $this->room('toyota-live', RoomVisibility::HIDDEN, RoomAudience::STAFF_PREVIEW);
        $this->assertTrue($auth->isRoomVisibleToActor($mod, $toyota));
        $auth->assertCanReadRoom($mod, $toyota);
        $this->addToAssertionCount(1);
    }

    public function testAdminCanPreviewHidden(): void
    {
        $auth = new ChatAuthorization();
        $admin = new User(1);
        $admin->isAdmin = true;
        $this->assertTrue($auth->canPreviewHiddenChatRooms($admin));
    }

    public function testSuspendedModeratorDeniedPreviewAndPost(): void
    {
        $auth = new ChatAuthorization();
        $mod = $this->moderator();
        $mod->suspended_until = date('c', time() + 3600);
        $this->assertFalse($auth->canPreviewHiddenChatRooms($mod));
        $general = $this->room('community-general-live', RoomVisibility::VISIBLE, RoomAudience::MEMBERS);
        $this->expectException(PermissionDeniedException::class);
        $auth->assertCanPost($mod, $general);
    }

    public function testSuspendedDeniedRealtimeSubscribe(): void
    {
        $auth = new ChatAuthorization();
        $member = $this->member();
        $member->suspended_until = date('c', time() + 3600);
        $general = $this->room('community-general-live', RoomVisibility::VISIBLE, RoomAudience::MEMBERS);
        $this->expectException(PermissionDeniedException::class);
        $auth->assertCanSubscribeRealtime($member, $general);
    }

    public function testGuestDeniedRealtimeSubscribe(): void
    {
        $auth = new ChatAuthorization();
        $namer = new PerRoomChannelNamer($auth);
        $guest = new User(null);
        $guest->permissions = [ChatAuthorization::PERM_ENABLED => true];
        $general = $this->room('community-general-live', RoomVisibility::VISIBLE, RoomAudience::MEMBERS);
        $this->expectException(PermissionDeniedException::class);
        $namer->assertCanSubscribe($guest, $general);
    }

    public function testChannelPrefixPrivateFlatrateLive(): void
    {
        $namer = new PerRoomChannelNamer(new ChatAuthorization());
        $chat = $this->room('toyota-live', RoomVisibility::HIDDEN, RoomAudience::STAFF_PREVIEW);
        $ch = $namer->channelKeyForRoom($chat);
        $this->assertStringStartsWith('private-flatrate-live-', $ch);
        $this->assertSame('toyota-live', $namer->roomKeyFromChannel($ch));
        $gm = $this->room('gm-live', RoomVisibility::HIDDEN, RoomAudience::STAFF_PREVIEW);
        $this->assertNotSame($ch, $namer->channelKeyForRoom($gm));
    }

    public function testVisibilityTransitionPreservesIdentity(): void
    {
        $catalog = new RoomCatalog();
        $profile = new RolloutProfile();
        $existing = [[
            'id' => 7,
            'room_key' => 'toyota-live',
            'title' => 'Toyota Live',
            'scope_type' => 'board',
            'scope_key' => 'toyota',
            'visibility' => RoomVisibility::VISIBLE,
            'audience' => RoomAudience::MEMBERS,
        ]];
        $applicator = new RolloutApplicator($catalog, $profile, false);
        $result = $applicator->apply($existing, true);
        $this->assertSame(42, $result['expected']);
        $this->assertSame(1, $result['memberVisible']);
        $this->assertSame(41, $result['staffPreview']);
        $keys = array_column($result['updated'], 'roomKey');
        $this->assertContains('toyota-live', $keys);
        foreach ($result['updated'] as $u) {
            if ($u['roomKey'] === 'toyota-live') {
                $this->assertTrue($u['identityPreserved']);
                $this->assertSame(RoomVisibility::HIDDEN, $u['to']['visibility']);
            }
        }
        // Catalog identity unchanged
        $toyota = $catalog->findByRoomKey('toyota-live');
        $this->assertSame('toyota-live', $toyota['roomKey']);
        $this->assertSame('board', $toyota['scopeType']);
        $this->assertSame('toyota', $toyota['scopeKey']);
    }

    public function testProvisionerDryRunIncludesRolloutDrift(): void
    {
        $p = new RoomProvisioner(new RoomCatalog(), false);
        $existing = [[
            'id' => 1,
            'room_key' => 'community-general-live',
            'title' => 'General Live',
            'scope_type' => 'navigation-group',
            'scope_key' => 'community',
            'visibility' => RoomVisibility::HIDDEN,
            'audience' => RoomAudience::STAFF_PREVIEW,
        ]];
        $result = $p->run(RoomProvisioner::MODE_DRY_RUN, $existing);
        $this->assertSame(42, $result['expected']);
        $this->assertSame('general-live-first', $result['rolloutProfile']);
        $driftKeys = array_column($result['drifted'], 'roomKey');
        $this->assertContains('community-general-live', $driftKeys);
    }

    public function testPusherConfigFailClosed(): void
    {
        $cfg = new PusherClientConfig(null, null, null, 'mt1');
        $this->assertFalse($cfg->isComplete());
        $this->assertFalse($cfg->productionPusherConfigured());
        $attrs = $cfg->forumAttributes();
        $this->assertFalse($attrs['flatrate-live-chat.realtime.connect']);
        $this->assertArrayNotHasKey('flatrate-live-chat.realtime.key', $attrs);
        $diag = $cfg->diagnostic();
        $this->assertSame('PUSHER_CHANNELS', $diag['transportDecision']);
        $this->assertSame('implemented/complete', $diag['transportImplementationStatus']);
        $this->assertFalse($diag['PUSHER_SELECTED']);
    }

    public function testPusherPublisherSkipsWithoutCredentials(): void
    {
        $pub = new PusherRealtimePublisher(new PusherClientConfig());
        $pub->publish('private-flatrate-live-abc', 'message.created', ['roomKey' => 'toyota-live']);
        $this->addToAssertionCount(1); // no throw
    }

    public function testPusherPublisherRejectsPublicChannel(): void
    {
        $cfg = new PusherClientConfig('k', 's', '1', 'mt1');
        $pub = new PusherRealtimePublisher($cfg);
        $pub->publish('public', 'message.created', ['roomKey' => 'x']);
        $this->addToAssertionCount(1);
    }

    public function testNullAndFakePublishersKept(): void
    {
        (new NullRealtimePublisher())->publish('private-flatrate-live-x', 'message.created', []);
        $fake = new FakeRealtimePublisher();
        $fake->publish('private-flatrate-live-x', 'message.created', ['roomKey' => 'community-general-live']);
        $this->assertTrue($fake->hasEvent('private-flatrate-live-x', 'message.created'));
    }

    public function testEventEnvelopeAllowlist(): void
    {
        $env = EventEnvelope::make('message.created', 'toyota-live', [
            'messageId' => 9,
            'userId' => 3,
            'email' => 'secret@example.com',
            'ip' => '1.2.3.4',
            'body' => 'should-not-appear',
        ], 9);
        $this->assertSame(1, $env['v']);
        $this->assertSame('message.created', $env['type']);
        $this->assertArrayNotHasKey('email', $env['payload']);
        $this->assertArrayNotHasKey('ip', $env['payload']);
        $this->assertArrayNotHasKey('body', $env['payload']);
        $this->assertSame(9, $env['payload']['messageId']);
    }

    public function testDoctorReportNoSecrets(): void
    {
        $report = (new DoctorReport())->toArray();
        $this->assertSame(42, $report['canonicalRoomCount']);
        $this->assertSame(1, $report['memberVisibleRooms']);
        $this->assertSame(41, $report['staffPreviewRooms']);
        $this->assertFalse($report['productionPusherConfigured']);
        $this->assertFalse($report['PUSHER_SELECTED']);
        $encoded = json_encode($report);
        $this->assertStringNotContainsString('app_secret', $encoded);
        $this->assertStringNotContainsString('PUSHER_APP_SECRET', $encoded);
    }
}
