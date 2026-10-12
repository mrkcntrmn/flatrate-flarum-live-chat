<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Admin\AdminDashboardService;
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

    private function service(bool $enabled = true): AdminDashboardService
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
            function (): array {
                $this->presenceCalls++;
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
        $this->assertSame('operational', $overview['health']['status']);
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
        $this->assertSame('degraded', $data['health']['status']);

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
}
