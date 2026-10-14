<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Admin\AdminDashboardService;
use FlatRate\LiveChat\Admin\DashboardMetricCalculator;
use FlatRate\LiveChat\Auth\MemberBetaGate;
use FlatRate\LiveChat\Api\Controllers\AdminDashboardController;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Provisioner\RoomReconcileSnapshot;
use FlatRate\LiveChat\Realtime\CentrifugoChannelNamer;
use FlatRate\LiveChat\Realtime\CentrifugoClientConfig;
use FlatRate\LiveChat\Realtime\CentrifugoPresenceStatsReader;
use FlatRate\LiveChat\Rollout\RolloutProfile;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class AdminDashboardSettings implements SettingsRepositoryInterface
{
    public function __construct(private array $values)
    {
    }

    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }
}

class AdminLiveChatDashboardTest extends TestCase
{
    private RoomCatalog $catalog;
    private RolloutProfile $rollout;
    private array $rows;
    private int $presenceCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new RoomCatalog();
        $this->rollout = new RolloutProfile();
        $this->rows = [];
        $id = 1;
        foreach ($this->catalog->rooms() as $room) {
            $policy = $this->rollout->policyForRoomKey($room['roomKey']);
            $this->rows[] = [
                'id' => $id++,
                'type' => 1,
                'title' => $room['name'],
                'room_key' => $room['roomKey'],
                'scope_type' => $room['scopeType'],
                'scope_key' => $room['scopeKey'],
                'visibility' => $policy['visibility'],
                'audience' => $policy['audience'],
            ];
        }
        $this->presenceCalls = 0;
    }

    private function service(bool $enabled = true, ?callable $presence = null): AdminDashboardService
    {
        $settings = new AdminDashboardSettings([
            AdminDashboardService::ENABLED_SETTING_KEY => $enabled ? '1' : '0',
            'flatrate-live-chat.general_live_enabled' => '1',
            'flatrate-live-chat.general_live_admin_preview_enabled' => '1',
            'flatrate-live-chat.general_live_user_enabled' => '0',
            'flatrate-live-chat.member_beta_enabled' => '0',
        ]);
        $reconcile = new RoomReconcileSnapshot($this->catalog, $this->rollout);
        $config = new CentrifugoClientConfig(
            CentrifugoClientConfig::DEFAULT_WEBSOCKET_URL,
            CentrifugoClientConfig::DEFAULT_PUBLISH_URL,
            'edge-fixture',
            'api-fixture',
            'private-key-fixture'
        );
        $presence = new CentrifugoPresenceStatsReader(
            $config,
            null,
            function () use ($presence): array {
                $this->presenceCalls++;
                if ($presence !== null) {
                    return $presence();
                }

                return ['status' => 200, 'body' => json_encode(['result' => ['num_users' => 3]])];
            }
        );

        return new AdminDashboardService(
            $this->catalog,
            $this->rollout,
            $reconcile,
            $settings,
            new CentrifugoChannelNamer(new ChatAuthorization($settings), $this->catalog),
            $presence,
            fn (): array => $this->rows,
            fn (array $ids, int $now): array => [[
                'chat_id' => 46,
                'messages_1h' => 2,
                'messages_24h' => 7,
                'messages_7d' => 19,
                'event_messages_24h' => 1,
                'unique_posters_24h' => 4,
                'last_activity_at' => gmdate('Y-m-d H:i:s', $now - 30),
            ]],
            fn (): int => 1791828000
        );
    }

    private function admin(): User
    {
        $user = new User(1);
        $user->isAdmin = true;
        return $user;
    }

    public function testSnapshotIsBoundedAggregateOnlyAndPresenceIsOnDemand(): void
    {
        $dashboard = $this->service();
        $overview = $dashboard->snapshot();

        $this->assertSame(46, count($overview['rooms']));
        $this->assertSame('catalog_reconciled', $overview['health']['status']);
        $this->assertSame('reconciled', $overview['health']['catalog']);
        $this->assertSame('not_instrumented', $overview['health']['historicalRealtimeMetrics']);
        $this->assertNotSame('operational', $overview['health']['status']);
        $this->assertSame('selected-room-on-demand', $overview['health']['presenceMode']);
        $this->assertSame(7, $overview['totals']['messages24h']);
        $this->assertSame(0, $this->presenceCalls);
        $this->assertFalse($overview['gates']['userLiveEnabled']);
        $this->assertFalse($overview['gates']['memberBetaEnabled']);

        $detail = $dashboard->snapshot('community-general-live');
        $general = array_values(array_filter(
            $detail['rooms'],
            fn (array $room): bool => $room['roomKey'] === 'community-general-live'
        ))[0];
        $this->assertSame(3, $general['presence']['uniqueUsers']);
        $this->assertSame(1, $this->presenceCalls);
        $this->assertTrue($general['effectiveAvailability']['adminPreview']);
        $this->assertFalse($general['effectiveAvailability']['broadMember']);

        $blob = json_encode($detail);
        foreach (['messageBody', 'messageText', 'participants', 'userIds', 'edge-fixture', 'api-fixture', 'private-key-fixture'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $blob);
        }
    }

    public function testMissingAndDriftedRoomsAreExplicitAndUnknownPresenceFailsClosed(): void
    {
        array_shift($this->rows);
        $this->rows[0]['audience'] = 'members';
        $dashboard = $this->service();
        $data = $dashboard->snapshot();

        $states = array_column($data['rooms'], 'catalogState', 'roomKey');
        $this->assertSame('missing', $states['acura-live']);
        $this->assertSame('drifted', $states['alfa-romeo-live']);
        $this->assertSame('catalog_attention', $data['health']['status']);
        $this->assertSame('attention', $data['health']['catalog']);

        $this->expectException(\InvalidArgumentException::class);
        $dashboard->snapshot('not-a-canonical-room');
    }

    public function testControllerDeniesNonAdminsAndFeatureGateDefaultsOff(): void
    {
        $disabled = new AdminDashboardController($this->service(false), new ChatAuthorization());
        $adminRequest = (new ServerRequest('GET', '/api/flatrate-live-chat/admin/dashboard'))
            ->withAttribute('actor', $this->admin());
        $this->assertSame(404, $disabled->handle($adminRequest)->getStatusCode());

        $enabled = new AdminDashboardController($this->service(true), new ChatAuthorization());
        foreach ([new User(null), new User(8)] as $actor) {
            $request = (new ServerRequest('GET', '/api/flatrate-live-chat/admin/dashboard'))
                ->withAttribute('actor', $actor);
            $this->assertSame(403, $enabled->handle($request)->getStatusCode());
        }

        $response = $enabled->handle($adminRequest);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));

        $bad = (new ServerRequest('GET', '/api/flatrate-live-chat/admin/dashboard?url=https://example.com'))
            ->withAttribute('actor', $this->admin())
            ->withQueryParams(['url' => 'https://example.com']);
        $this->assertSame(400, $enabled->handle($bad)->getStatusCode());
    }

    public function testAuthorizationMatrixDeniesHiddenCatalogData(): void
    {
        $member = new User(8);
        $beta = new User(21);
        $betaGate = new MemberBetaGate(new AdminDashboardSettings([
            MemberBetaGate::SETTING_KEY => '1',
        ]), new class {
            public function isActive(User $actor): bool
            {
                return true;
            }
        }, true);
        $this->assertTrue($betaGate->allowsActor($beta));
        $this->assertFalse($beta->isAdmin());

        $moderator = new User(14);
        $moderator->permissions = [ChatAuthorization::PERM_MODERATE => true];
        $this->assertFalse($moderator->isAdmin());

        $suspended = new User(3);
        $suspended->isAdmin = true;
        $suspended->suspended_until = date('c', time() + 3600);

        $rootOnly = new User(1);
        $rootOnly->domainRole = 'OWNER_ROOT';
        $this->assertFalse($rootOnly->isAdmin());

        $denied = [
            'guest' => new User(null),
            'member' => $member,
            'beta' => $beta,
            'moderator' => $moderator,
            'suspended-admin' => $suspended,
            'owner-root' => $rootOnly,
        ];

        foreach ([false, true] as $enabledFlag) {
            $controller = new AdminDashboardController($this->service($enabledFlag), new ChatAuthorization());
            foreach ($denied as $label => $actor) {
                $response = $controller->handle($this->request($actor));
                $this->assertSame(403, $response->getStatusCode(), $label . ($enabledFlag ? ' on' : ' off'));
                $this->assertPrivateHeaders($response);
                $this->assertNoCatalogLeak((string) $response->getBody());
            }
        }

        $off = new AdminDashboardController($this->service(false), new ChatAuthorization());
        $disabledAdmin = $off->handle($this->request($this->admin()));
        $this->assertSame(404, $disabledAdmin->getStatusCode());
        $this->assertPrivateHeaders($disabledAdmin);
        $this->assertNoCatalogLeak((string) $disabledAdmin->getBody());

        $on = new AdminDashboardController($this->service(true), new ChatAuthorization());
        $allowed = $on->handle($this->request($this->admin()));
        $this->assertSame(200, $allowed->getStatusCode());
        $this->assertPrivateHeaders($allowed);
    }

    public function testPresenceZeroIsDistinctFromUnavailableAndOnlyOneRoomIsPolled(): void
    {
        $zero = $this->service(true, static fn (): array => [
            'status' => 200,
            'body' => json_encode(['result' => ['num_users' => 0]]),
        ]);
        $detail = $zero->snapshot('honda-live');
        $honda = $this->room($detail, 'honda-live');
        $general = $this->room($detail, 'community-general-live');
        $this->assertSame(0, $honda['presence']['uniqueUsers']);
        $this->assertTrue($honda['presence']['available']);
        $this->assertNull($general['presence']);
        $this->assertSame(1, $this->presenceCalls);
        $this->assertFalse($honda['effectiveAvailability']['broadMember']);
        $this->assertTrue($general['effectiveAvailability']['adminPreview']);
        $this->assertFalse($general['effectiveAvailability']['broadMember']);

        $down = $this->service(true, static function (): array {
            throw new \RuntimeException('presence timeout');
        });
        $failed = $this->room($down->snapshot('audi-live'), 'audi-live');
        $this->assertFalse($failed['presence']['available']);
        $this->assertNull($failed['presence']['uniqueUsers']);

        $http = $this->service(true, static fn (): array => ['status' => 503, 'body' => '{"error":"down"}']);
        $provider = $this->room($http->snapshot('bmw-live'), 'bmw-live');
        $this->assertFalse($provider['presence']['available']);
        $this->assertNull($provider['presence']['uniqueUsers']);
    }

    public function testUtcWindowsExcludeDeletedRowsAndKeepLifetimeLastActivity(): void
    {
        $now = 1791828000;
        $bounds = DashboardMetricCalculator::bounds($now);
        $this->assertSame('2026-10-12 17:00:00', $bounds['hour']);
        $this->assertSame('2026-10-11 18:00:00', $bounds['day']);
        $this->assertSame('2026-10-05 18:00:00', $bounds['week']);

        $rows = [
            ['chat_id' => 1, 'type' => 0, 'user_id' => 9, 'created_at' => '2026-09-01 00:00:00', 'deleted_by' => null],
            ['chat_id' => 1, 'type' => 0, 'user_id' => 9, 'created_at' => '2026-10-12 17:00:00', 'deleted_by' => null],
            ['chat_id' => 1, 'type' => 0, 'user_id' => 9, 'created_at' => '2026-10-12 16:59:59', 'deleted_by' => null],
            ['chat_id' => 1, 'type' => 0, 'user_id' => 4, 'created_at' => '2026-10-12 17:30:00', 'deleted_by' => 2],
            ['chat_id' => 1, 'type' => 1, 'user_id' => 9, 'created_at' => '2026-10-12 17:45:00', 'deleted_by' => null],
            ['chat_id' => 2, 'type' => 0, 'user_id' => 5, 'created_at' => '2026-08-01 00:00:00', 'deleted_by' => null],
            ['chat_id' => 2, 'type' => 0, 'user_id' => null, 'created_at' => '2026-10-11 18:00:00', 'deleted_by' => null],
            ['chat_id' => 3, 'type' => 0, 'user_id' => 7, 'created_at' => '2026-10-12 17:50:00', 'deleted_by' => 1],
        ];
        $metrics = DashboardMetricCalculator::aggregate($rows, $now);

        $this->assertSame(1, $metrics[1]['messages_1h']);
        $this->assertSame(2, $metrics[1]['messages_24h']);
        $this->assertSame(2, $metrics[1]['messages_7d']);
        $this->assertSame(1, $metrics[1]['event_messages_24h']);
        $this->assertSame(1, $metrics[1]['unique_posters_24h']);
        $this->assertSame('2026-10-12 17:45:00', $metrics[1]['last_activity_at']);
        $this->assertSame(0, $metrics[2]['messages_1h']);
        $this->assertSame(1, $metrics[2]['messages_24h']);
        $this->assertSame(0, $metrics[2]['unique_posters_24h']);
        $this->assertSame('2026-10-11 18:00:00', $metrics[2]['last_activity_at']);
        $this->assertArrayNotHasKey(3, $metrics);
    }

    private function request(User $actor): ServerRequest
    {
        return (new ServerRequest('GET', '/api/flatrate-live-chat/admin/dashboard'))
            ->withAttribute('actor', $actor);
    }

    private function assertPrivateHeaders($response): void
    {
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
    }

    private function assertNoCatalogLeak(string $body): void
    {
        $this->assertStringNotContainsString('staff-preview', $body);
        foreach ($this->catalog->rooms() as $room) {
            $this->assertStringNotContainsString($room['name'], $body);
            $this->assertStringNotContainsString($room['roomKey'], $body);
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function room(array $snapshot, string $roomKey): array
    {
        foreach ($snapshot['rooms'] as $room) {
            if ($room['roomKey'] === $roomKey) {
                return $room;
            }
        }
        $this->fail('missing room ' . $roomKey);
    }
}
