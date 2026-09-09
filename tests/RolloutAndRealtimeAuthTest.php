<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\Console\DoctorReport;
use FlatRate\LiveChat\Provisioner\RoomProvisioner;
use FlatRate\LiveChat\Realtime\CentrifugoChannelNamer;
use FlatRate\LiveChat\Realtime\CentrifugoClientConfig;
use FlatRate\LiveChat\Realtime\CentrifugoRealtimePublisher;
use FlatRate\LiveChat\Realtime\EventEnvelope;
use FlatRate\LiveChat\Realtime\FakeRealtimePublisher;
use FlatRate\LiveChat\Realtime\NullRealtimePublisher;
use FlatRate\LiveChat\Realtime\RsaRealtimeTokenIssuer;
use FlatRate\LiveChat\Rollout\RolloutApplicator;
use FlatRate\LiveChat\Rollout\RolloutProfile;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use PHPUnit\Framework\TestCase;

class RolloutAndRealtimeAuthTest extends TestCase
{
    private ?string $testPrivateKey = null;
    private ?string $testPublicKey = null;

    protected function setUp(): void
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($res);
        openssl_pkey_export($res, $this->testPrivateKey);
        $details = openssl_pkey_get_details($res);
        $this->testPublicKey = $details['key'];
    }

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

    private function completeConfig(
        ?string $ws = 'wss://realtime.flatrate.wiki/connection/websocket',
        ?string $publish = 'https://realtime.flatrate.wiki/api/publish'
    ): CentrifugoClientConfig {
        return new CentrifugoClientConfig(
            $ws,
            $publish,
            'edge-key',
            'api-key',
            $this->testPrivateKey,
            CentrifugoClientConfig::JWT_ALGORITHM,
            CentrifugoClientConfig::DEFAULT_ISSUER,
            CentrifugoClientConfig::DEFAULT_AUDIENCE,
            300,
            300,
            false,
            false
        );
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
        $namer = new CentrifugoChannelNamer($auth);
        $guest = new User(null);
        $guest->permissions = [ChatAuthorization::PERM_ENABLED => true];
        $general = $this->room('community-general-live', RoomVisibility::VISIBLE, RoomAudience::MEMBERS);
        $this->expectException(PermissionDeniedException::class);
        $namer->assertCanSubscribe($guest, $general);
    }

    public function testCentrifugoChannelNaming(): void
    {
        $namer = new CentrifugoChannelNamer(new ChatAuthorization());
        $chat = $this->room('toyota-live', RoomVisibility::HIDDEN, RoomAudience::STAFF_PREVIEW);
        $ch = $namer->channelForRoom($chat);
        $this->assertSame('$flatrate-live-toyota-live', $ch);
        $this->assertSame('toyota-live', $namer->roomKeyFromChannel($ch));
        $this->assertSame(
            '$flatrate-live-community-general-live',
            $namer->channelForRoomKey('community-general-live')
        );
        $gm = $this->room('gm-live', RoomVisibility::HIDDEN, RoomAudience::STAFF_PREVIEW);
        $this->assertNotSame($ch, $namer->channelForRoom($gm));
        $this->assertNull($namer->roomKeyFromChannel('private-flatrate-live-toyota-live'));
        $this->assertNull($namer->roomKeyFromChannel('$flatrate-live-not-a-real-room'));
        $this->assertNull($namer->roomKeyFromChannel('$flatrate-live-'));
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

    public function testCentrifugoConfigFailClosed(): void
    {
        $cfg = new CentrifugoClientConfig();
        $this->assertFalse($cfg->isComplete());
        $attrs = $cfg->forumAttributes();
        $this->assertFalse($attrs['flatrate-live-chat.realtime.connect']);
        $this->assertArrayNotHasKey('flatrate-live-chat.realtime.websocketUrl', $attrs);
        $this->assertArrayNotHasKey('flatrate-live-chat.realtime.edgeKey', $attrs);
        $this->assertSame('CENTRIFUGO', $attrs['flatrate-live-chat.realtime.transport']);
        $diag = $cfg->diagnostic();
        $this->assertSame('CENTRIFUGO_SELF_HOSTED', $diag['transportDecision']);
        $this->assertFalse($diag['runtimeConfigured']);
        $this->assertFalse($diag['credentialsComplete']);
        $this->assertArrayNotHasKey('productionCentrifugoConfigured', $diag);
        $this->assertArrayNotHasKey('transportExternalQualification', $diag);
        $this->assertArrayNotHasKey('NEXT_VERSION', $diag);
    }

    public function testCentrifugoTlsRejectsInsecureProductionHost(): void
    {
        $cfg = new CentrifugoClientConfig(
            'ws://realtime.flatrate.wiki/connection/websocket',
            'http://realtime.flatrate.wiki/api/publish',
            'edge',
            'api',
            $this->testPrivateKey
        );
        $this->assertFalse($cfg->urlsAreTlsSafe());
        $this->assertFalse($cfg->isComplete());

        $ok = $this->completeConfig();
        $this->assertTrue($ok->isComplete());
        $attrs = $ok->forumAttributes();
        $this->assertTrue($attrs['flatrate-live-chat.realtime.connect']);
        $this->assertSame('wss://realtime.flatrate.wiki/connection/websocket', $attrs['flatrate-live-chat.realtime.websocketUrl']);
        $this->assertArrayNotHasKey('flatrate-live-chat.realtime.publishApiUrl', $attrs);
        $this->assertArrayNotHasKey('flatrate-live-chat.realtime.apiKey', $attrs);
    }

    public function testCentrifugoRejectsWrongHostOrPath(): void
    {
        $wrongHost = $this->completeConfig('wss://evil.example/connection/websocket', 'https://realtime.flatrate.wiki/api/publish');
        $this->assertFalse($wrongHost->isComplete());
        $wrongPath = $this->completeConfig('wss://realtime.flatrate.wiki/connection/websocket', 'https://realtime.flatrate.wiki/api/broadcast');
        $this->assertFalse($wrongPath->isComplete());
    }

    public function testCentrifugoAllowInsecureLocalhost(): void
    {
        $cfg = new CentrifugoClientConfig(
            'ws://localhost:8000/connection/websocket',
            'http://127.0.0.1:8000/api/publish',
            'edge',
            'api',
            $this->testPrivateKey,
            CentrifugoClientConfig::JWT_ALGORITHM,
            CentrifugoClientConfig::DEFAULT_ISSUER,
            CentrifugoClientConfig::DEFAULT_AUDIENCE,
            300,
            300,
            false,
            true
        );
        $this->assertTrue($cfg->urlsAreTlsSafe());
        $this->assertTrue($cfg->isComplete());
    }

    public function testCentrifugoPublisherSkipsWithoutCredentials(): void
    {
        $pub = new CentrifugoRealtimePublisher(new CentrifugoClientConfig());
        $pub->publish('$flatrate-live-toyota-live', 'message.created', ['roomKey' => 'toyota-live']);
        $this->addToAssertionCount(1);
    }

    public function testCentrifugoPublisherRejectsPublicChannel(): void
    {
        $calls = 0;
        $pub = new CentrifugoRealtimePublisher($this->completeConfig(), null, function () use (&$calls) {
            $calls++;
            return ['status' => 200, 'body' => '{"result":{}}'];
        });
        $pub->publish('public', 'message.created', ['roomKey' => 'x']);
        $this->assertSame(0, $calls);
    }

    public function testCentrifugoPublisherChannelRoomMismatchDenied(): void
    {
        $calls = 0;
        $pub = new CentrifugoRealtimePublisher($this->completeConfig(), null, function () use (&$calls) {
            $calls++;
            return ['status' => 200, 'body' => '{"result":{}}'];
        });
        $pub->publish('$flatrate-live-toyota-live', 'message.created', ['roomKey' => 'gm-live']);
        $pub->publish('$flatrate-live-fake-room', 'message.created', ['roomKey' => 'fake-room']);
        $pub->publish('$flatrate-live-', 'message.created', ['roomKey' => '']);
        $this->assertSame(0, $calls);
    }

    public function testCentrifugoPublisherSuccessAndJsonErrorAndRetry(): void
    {
        $bodies = [];
        $statuses = [200, 200];
        $responses = ['{"error":{"code":100}}', '{"result":{}}'];
        $i = 0;
        $pub = new CentrifugoRealtimePublisher($this->completeConfig(), null, function ($url, $headers, $body) use (&$i, &$bodies, $statuses, $responses) {
            $bodies[] = $body;
            $this->assertSame('edge-key', $headers['X-FlatRate-Realtime-Key']);
            $this->assertSame('api-key', $headers['X-API-Key']);
            $decoded = json_decode($body, true);
            $this->assertSame('$flatrate-live-community-general-live', $decoded['channel']);
            $this->assertArrayHasKey('idempotency_key', $decoded);
            $this->assertStringStartsWith('event:', $decoded['idempotency_key']);
            $this->assertSame(2, $decoded['data']['v']);
            $this->assertNotEmpty($decoded['data']['eventId']);
            $idx = $i++;
            return ['status' => $statuses[$idx], 'body' => $responses[$idx]];
        });
        $pub->publish('$flatrate-live-community-general-live', 'message.created', [
            'roomKey' => 'community-general-live',
            'messageId' => 42,
            'order' => 42,
            'eventId' => 'fixed-event-id-1',
        ]);
        $this->assertCount(2, $bodies);
        $this->assertSame($bodies[0], $bodies[1]);
        $decoded = json_decode($bodies[0], true);
        $this->assertSame('event:fixed-event-id-1', $decoded['idempotency_key']);
        $this->assertSame('fixed-event-id-1', $decoded['data']['eventId']);
    }

    public function testCentrifugoPublisherMalformed2xxDenied(): void
    {
        foreach (['', 'hello', '{}', '{"unexpected":true}'] as $body) {
            $calls = 0;
            $pub = new CentrifugoRealtimePublisher($this->completeConfig(), null, function () use (&$calls, $body) {
                $calls++;
                return ['status' => 200, 'body' => $body];
            });
            $pub->publish('$flatrate-live-toyota-live', 'message.created', [
                'roomKey' => 'toyota-live',
                'eventId' => 'e-' . md5($body),
            ]);
            // Initial + one retry
            $this->assertSame(2, $calls, 'body=' . $body);
        }
    }

    public function testCentrifugoPublisherTimeoutNeverThrows(): void
    {
        $pub = new CentrifugoRealtimePublisher($this->completeConfig(), null, function () {
            throw new \RuntimeException('timeout');
        });
        $pub->publish('$flatrate-live-toyota-live', 'message.created', ['roomKey' => 'toyota-live', 'messageId' => 1]);
        $this->addToAssertionCount(1);
    }

    public function testRepeatedEditsGetDistinctEventIds(): void
    {
        $ids = [];
        $pub = new CentrifugoRealtimePublisher($this->completeConfig(), null, function ($url, $headers, $body) use (&$ids) {
            $decoded = json_decode($body, true);
            $ids[] = $decoded['data']['eventId'];
            return ['status' => 200, 'body' => '{"result":{}}'];
        });
        $pub->publish('$flatrate-live-community-general-live', 'message.edited', [
            'roomKey' => 'community-general-live',
            'messageId' => 42,
            'order' => 42,
        ]);
        $pub->publish('$flatrate-live-community-general-live', 'message.edited', [
            'roomKey' => 'community-general-live',
            'messageId' => 42,
            'order' => 42,
        ]);
        $this->assertCount(2, $ids);
        $this->assertNotSame($ids[0], $ids[1]);
    }

    public function testNullAndFakePublishersKept(): void
    {
        (new NullRealtimePublisher())->publish('$flatrate-live-x', 'message.created', []);
        $fake = new FakeRealtimePublisher();
        $fake->publish('$flatrate-live-community-general-live', 'message.created', ['roomKey' => 'community-general-live']);
        $this->assertTrue($fake->hasEvent('$flatrate-live-community-general-live', 'message.created'));
    }

    public function testSingleEmissionAuthorityPostMessageHandlerHasNoDirectPublish(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/src/Commands/PostMessageHandler.php');
        $this->assertStringNotContainsString('use FlatRate\\LiveChat\\Realtime\\RealtimePublisher', $src);
        $this->assertStringNotContainsString('CentrifugoChannelNamer', $src);
        $this->assertDoesNotMatchRegularExpression('/\$this->realtime\s*->\s*publish\s*\(/', $src);
        $this->assertStringContainsString('new Saved(', $src);
    }

    public function testRsaTokenIssuerConnectionAndSubscription(): void
    {
        $cfg = $this->completeConfig();
        $issuer = new RsaRealtimeTokenIssuer($cfg);
        $conn = $issuer->issueConnectionToken('10');
        $this->assertNotEmpty($conn['token']);
        $this->assertGreaterThan(time(), $conn['expiresAt']);

        $decoded = JWT::decode($conn['token'], new Key($this->testPublicKey, 'RS256'));
        $this->assertSame('10', $decoded->sub);
        $this->assertSame('flatrate-forum', $decoded->iss);
        $this->assertSame('flatrate-realtime', $decoded->aud);
        $this->assertObjectNotHasProperty('channel', $decoded);

        $sub = $issuer->issueSubscriptionToken('10', '$flatrate-live-community-general-live');
        $subDecoded = JWT::decode($sub['token'], new Key($this->testPublicKey, 'RS256'));
        $this->assertSame('$flatrate-live-community-general-live', $subDecoded->channel);
    }

    public function testRsaTokenIssuerFailsClosedOnBadKey(): void
    {
        $cfg = new CentrifugoClientConfig(
            'wss://realtime.flatrate.wiki/connection/websocket',
            'https://realtime.flatrate.wiki/api/publish',
            'edge',
            'api',
            'not-a-pem',
            CentrifugoClientConfig::JWT_ALGORITHM,
            CentrifugoClientConfig::DEFAULT_ISSUER,
            CentrifugoClientConfig::DEFAULT_AUDIENCE,
            300,
            300,
            false,
            false
        );
        // isComplete is true structurally, but encode must fail closed.
        $issuer = new RsaRealtimeTokenIssuer($cfg);
        $this->expectException(PermissionDeniedException::class);
        $issuer->issueConnectionToken('10');
    }

    public function testRsaTokenIssuerRejectsNonPrivateChannel(): void
    {
        $issuer = new RsaRealtimeTokenIssuer($this->completeConfig());
        $this->expectException(PermissionDeniedException::class);
        $issuer->issueSubscriptionToken('10', 'public-channel');
    }

    public function testConnectTokenAuthMatrixGates(): void
    {
        $auth = new ChatAuthorization();
        $guest = new User(null);
        $this->expectException(PermissionDeniedException::class);
        $auth->assertNotGuest($guest);
    }

    public function testSubscriptionAuthMatrixMemberHiddenDenied(): void
    {
        $auth = new ChatAuthorization();
        $member = $this->member();
        $toyota = $this->room('toyota-live', RoomVisibility::HIDDEN, RoomAudience::STAFF_PREVIEW);
        $this->expectException(PermissionDeniedException::class);
        $auth->assertCanSubscribeRealtime($member, $toyota);
    }

    public function testSubscriptionAuthMatrixMemberGeneralAllowed(): void
    {
        $auth = new ChatAuthorization();
        $namer = new CentrifugoChannelNamer($auth);
        $general = $this->room('community-general-live', RoomVisibility::VISIBLE, RoomAudience::MEMBERS);
        $channel = $namer->assertCanSubscribe($this->member(), $general);
        $this->assertSame('$flatrate-live-community-general-live', $channel);
    }

    public function testEventEnvelopeAllowlist(): void
    {
        $env = EventEnvelope::make('message.created', 'toyota-live', [
            'messageId' => 9,
            'userId' => 3,
            'email' => 'secret@example.com',
            'ip' => '1.2.3.4',
            'body' => 'should-not-appear',
        ], 9, 'deterministic-event-id');
        $this->assertSame(2, $env['v']);
        $this->assertSame('deterministic-event-id', $env['eventId']);
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
        $this->assertArrayNotHasKey('productionCentrifugoConfigured', $report);
        $this->assertArrayNotHasKey('NEXT_VERSION', $report);
        $this->assertSame('CENTRIFUGO_SELF_HOSTED', $report['transport']['transportDecision']);
        $encoded = json_encode($report);
        $this->assertStringNotContainsString('edge-key', $encoded);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $encoded);
        $this->assertStringNotContainsString('api-key', $encoded);
        $this->assertStringNotContainsString('pusher', strtolower($encoded));
    }
}
