<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Api\Controllers\RealtimeEgressProbeController;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Realtime\CentrifugoClientConfig;
use FlatRate\LiveChat\Realtime\CentrifugoSecretResolver;
use FlatRate\LiveChat\Realtime\RealtimeEgressProbe;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class SecretFilesAndEgressProbeTest extends TestCase
{
    private ?string $testPrivateKey = null;
    /** @var list<string> */
    private array $envKeys = [
        'FLATRATE_LIVE_CHAT_CENTRIFUGO_WS_URL',
        'FLATRATE_LIVE_CHAT_CENTRIFUGO_PUBLISH_URL',
        'FLATRATE_LIVE_CHAT_CENTRIFUGO_EDGE_KEY',
        'FLATRATE_LIVE_CHAT_CENTRIFUGO_API_KEY',
        'FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_PRIVATE_KEY',
        'FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_PRIVATE_KEY_FILE',
        'FLATRATE_LIVE_CHAT_CENTRIFUGO_EDGE_KEY_FILE',
        'FLATRATE_LIVE_CHAT_CENTRIFUGO_API_KEY_FILE',
        'FLATRATE_LIVE_CHAT_CENTRIFUGO_DISABLED',
        'FLATRATE_LIVE_CHAT_ALLOW_INSECURE_REALTIME',
    ];
    /** @var array<string,string|false> */
    private array $envBackup = [];
    private ?string $tempDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->envKeys as $key) {
            $v = getenv($key);
            $this->envBackup[$key] = $v === false ? false : $v;
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($res);
        openssl_pkey_export($res, $this->testPrivateKey);

        $this->tempDir = sys_get_temp_dir() . '/fr-live-chat-002a-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        if ($this->tempDir && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function writeSecret(string $name, string $contents): string
    {
        $path = $this->tempDir . '/' . $name;
        file_put_contents($path, $contents);
        chmod($path, 0600);

        return $path;
    }

    private function writeCanonicalTrio(): void
    {
        $this->writeSecret(CentrifugoSecretResolver::EDGE_KEY_FILENAME, "edge-from-file\n");
        $this->writeSecret(CentrifugoSecretResolver::API_KEY_FILENAME, "api-from-file\n");
        $this->writeSecret(CentrifugoSecretResolver::JWT_PRIVATE_KEY_FILENAME, $this->testPrivateKey);
    }

    public function testDirectEnvParityStillWorks(): void
    {
        $this->setEnv('FLATRATE_LIVE_CHAT_CENTRIFUGO_WS_URL', CentrifugoClientConfig::DEFAULT_WEBSOCKET_URL);
        $this->setEnv('FLATRATE_LIVE_CHAT_CENTRIFUGO_PUBLISH_URL', CentrifugoClientConfig::DEFAULT_PUBLISH_URL);
        $this->setEnv('FLATRATE_LIVE_CHAT_CENTRIFUGO_EDGE_KEY', 'edge-env');
        $this->setEnv('FLATRATE_LIVE_CHAT_CENTRIFUGO_API_KEY', 'api-env');
        $this->setEnv('FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_PRIVATE_KEY', $this->testPrivateKey);

        $cfg = CentrifugoClientConfig::fromEnvironment($this->tempDir);
        $this->assertTrue($cfg->isComplete());
        $this->assertSame('edge-env', $cfg->edgeKey());
        $this->assertSame(CentrifugoSecretResolver::SOURCE_ENV_VALUE, $cfg->edgeKeySource());
        $this->assertSame(CentrifugoClientConfig::URL_SOURCE_ENV, $cfg->websocketUrlSource());
    }

    public function testCanonicalUrlDefaultsWhenEnvAbsent(): void
    {
        $this->writeCanonicalTrio();
        $cfg = CentrifugoClientConfig::fromEnvironment($this->tempDir);
        $this->assertTrue($cfg->isComplete());
        $this->assertSame(CentrifugoClientConfig::DEFAULT_WEBSOCKET_URL, $cfg->websocketUrl());
        $this->assertSame(CentrifugoClientConfig::DEFAULT_PUBLISH_URL, $cfg->publishApiUrl());
        $this->assertSame(CentrifugoClientConfig::URL_SOURCE_DEFAULT, $cfg->websocketUrlSource());
        $this->assertSame(CentrifugoClientConfig::URL_SOURCE_DEFAULT, $cfg->publishUrlSource());
        $this->assertTrue($cfg->urlsAreTlsSafe());
    }

    public function testZeroCustomEnvFileOnlyMode(): void
    {
        $this->writeCanonicalTrio();
        $cfg = CentrifugoClientConfig::fromEnvironment($this->tempDir);
        $this->assertTrue($cfg->isComplete());
        $diag = $cfg->diagnostic();
        $this->assertSame('default', $diag['websocketUrlSource']);
        $this->assertSame('default', $diag['publishUrlSource']);
        $this->assertSame('default-file', $diag['edgeKeySource']);
        $this->assertSame('default-file', $diag['apiKeySource']);
        $this->assertSame('default-file', $diag['jwtPrivateKeySource']);
        $this->assertSame('file-fallback', $diag['secretMode']);
        $this->assertSame('edge-from-file', $cfg->edgeKey());
        $this->assertSame('api-from-file', $cfg->apiKey());
    }

    public function testDirectEnvPrecedenceOverDefaultFile(): void
    {
        $this->writeCanonicalTrio();
        $this->setEnv('FLATRATE_LIVE_CHAT_CENTRIFUGO_EDGE_KEY', 'edge-wins');
        $this->setEnv('FLATRATE_LIVE_CHAT_CENTRIFUGO_API_KEY', 'api-wins');
        $this->setEnv('FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_PRIVATE_KEY', $this->testPrivateKey);
        $cfg = CentrifugoClientConfig::fromEnvironment($this->tempDir);
        $this->assertTrue($cfg->isComplete());
        $this->assertSame('edge-wins', $cfg->edgeKey());
        $this->assertSame(CentrifugoSecretResolver::SOURCE_ENV_VALUE, $cfg->edgeKeySource());
        $encoded = json_encode($cfg->diagnostic());
        $this->assertStringNotContainsString('edge-wins', $encoded);
        $this->assertStringNotContainsString('BEGIN', $encoded);
    }

    public function testExplicitFilePrecedenceOverDefaultFile(): void
    {
        $this->writeCanonicalTrio();
        $explicitEdge = $this->writeSecret('explicit-edge', 'explicit-edge-value');
        $explicitApi = $this->writeSecret('explicit-api', 'explicit-api-value');
        $explicitJwt = $this->writeSecret('explicit.pem', $this->testPrivateKey);
        $this->setEnv('FLATRATE_LIVE_CHAT_CENTRIFUGO_EDGE_KEY_FILE', $explicitEdge);
        $this->setEnv('FLATRATE_LIVE_CHAT_CENTRIFUGO_API_KEY_FILE', $explicitApi);
        $this->setEnv('FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_PRIVATE_KEY_FILE', $explicitJwt);

        $cfg = CentrifugoClientConfig::fromEnvironment($this->tempDir);
        $this->assertTrue($cfg->isComplete());
        $this->assertSame('explicit-edge-value', $cfg->edgeKey());
        $this->assertSame(CentrifugoSecretResolver::SOURCE_EXPLICIT_FILE, $cfg->edgeKeySource());
        $this->assertSame(CentrifugoSecretResolver::SOURCE_EXPLICIT_FILE, $cfg->apiKeySource());
        $this->assertSame(CentrifugoSecretResolver::SOURCE_EXPLICIT_FILE, $cfg->jwtPrivateKeySource());
    }

    public function testExplicitInvalidFileDoesNotFallThrough(): void
    {
        $this->writeCanonicalTrio();
        $this->setEnv('FLATRATE_LIVE_CHAT_CENTRIFUGO_EDGE_KEY_FILE', '/missing/path/edge');
        $cfg = CentrifugoClientConfig::fromEnvironment($this->tempDir);
        $this->assertFalse($cfg->isComplete());
        $this->assertSame(CentrifugoSecretResolver::SOURCE_INVALID_FILE, $cfg->edgeKeySource());
        $this->assertNull($cfg->edgeKey());
    }

    public function testMissingCanonicalFileFailClosed(): void
    {
        $cfg = CentrifugoClientConfig::fromEnvironment($this->tempDir);
        $this->assertFalse($cfg->isComplete());
        $this->assertSame(CentrifugoSecretResolver::SOURCE_MISSING, $cfg->edgeKeySource());
    }

    public function testEmptyFileFailClosed(): void
    {
        $this->writeSecret(CentrifugoSecretResolver::EDGE_KEY_FILENAME, '');
        $this->writeSecret(CentrifugoSecretResolver::API_KEY_FILENAME, 'api');
        $this->writeSecret(CentrifugoSecretResolver::JWT_PRIVATE_KEY_FILENAME, $this->testPrivateKey);
        $cfg = CentrifugoClientConfig::fromEnvironment($this->tempDir);
        $this->assertFalse($cfg->isComplete());
    }

    public function testOversizedFileFailClosed(): void
    {
        $this->writeSecret(CentrifugoSecretResolver::EDGE_KEY_FILENAME, str_repeat('a', CentrifugoSecretResolver::MAX_KEY_BYTES + 1));
        $this->writeSecret(CentrifugoSecretResolver::API_KEY_FILENAME, 'api');
        $this->writeSecret(CentrifugoSecretResolver::JWT_PRIVATE_KEY_FILENAME, $this->testPrivateKey);
        $cfg = CentrifugoClientConfig::fromEnvironment($this->tempDir);
        $this->assertFalse($cfg->isComplete());
    }

    public function testDirectoryPathFailClosed(): void
    {
        $this->setEnv('FLATRATE_LIVE_CHAT_CENTRIFUGO_EDGE_KEY_FILE', $this->tempDir);
        $this->writeSecret(CentrifugoSecretResolver::API_KEY_FILENAME, 'api');
        $this->writeSecret(CentrifugoSecretResolver::JWT_PRIVATE_KEY_FILENAME, $this->testPrivateKey);
        $cfg = CentrifugoClientConfig::fromEnvironment($this->tempDir);
        $this->assertFalse($cfg->isComplete());
        $this->assertSame(CentrifugoSecretResolver::SOURCE_INVALID_FILE, $cfg->edgeKeySource());
    }

    public function testEmbeddedNulFailClosed(): void
    {
        $path = $this->tempDir . '/' . CentrifugoSecretResolver::EDGE_KEY_FILENAME;
        file_put_contents($path, "abc\0def");
        chmod($path, 0600);
        $this->writeSecret(CentrifugoSecretResolver::API_KEY_FILENAME, 'api');
        $this->writeSecret(CentrifugoSecretResolver::JWT_PRIVATE_KEY_FILENAME, $this->testPrivateKey);
        $cfg = CentrifugoClientConfig::fromEnvironment($this->tempDir);
        $this->assertFalse($cfg->isComplete());
    }

    public function testMultilineEdgeKeyFailClosed(): void
    {
        $this->writeSecret(CentrifugoSecretResolver::EDGE_KEY_FILENAME, "line1\nline2\n");
        $this->writeSecret(CentrifugoSecretResolver::API_KEY_FILENAME, 'api');
        $this->writeSecret(CentrifugoSecretResolver::JWT_PRIVATE_KEY_FILENAME, $this->testPrivateKey);
        $cfg = CentrifugoClientConfig::fromEnvironment($this->tempDir);
        $this->assertFalse($cfg->isComplete());
    }

    public function testRsaPemFileLoads(): void
    {
        $this->writeCanonicalTrio();
        $cfg = CentrifugoClientConfig::fromEnvironment($this->tempDir);
        $this->assertTrue($cfg->isComplete());
        $this->assertStringContainsString('BEGIN', (string) $cfg->jwtPrivateKey());
        $this->assertSame(CentrifugoSecretResolver::SOURCE_DEFAULT_FILE, $cfg->jwtPrivateKeySource());
    }

    public function testForumAttributesNeverLeakSecretsOrPaths(): void
    {
        $this->writeCanonicalTrio();
        $cfg = CentrifugoClientConfig::fromEnvironment($this->tempDir);
        $attrs = $cfg->forumAttributes();
        $blob = json_encode($attrs);
        $this->assertStringNotContainsString('edge-from-file', $blob);
        $this->assertStringNotContainsString('api-from-file', $blob);
        $this->assertStringNotContainsString('BEGIN', $blob);
        $this->assertStringNotContainsString('/data/', $blob);
        $this->assertStringNotContainsString($this->tempDir, $blob);
        $this->assertStringNotContainsString('default-file', $blob);
        $this->assertArrayNotHasKey('flatrate-live-chat.realtime.publishUrl', $attrs);
        $this->assertTrue($attrs['flatrate-live-chat.realtime.configured']);
    }

    public function testAssertAdminAuthMatrix(): void
    {
        $auth = new ChatAuthorization();

        $guest = new User(null);
        try {
            $auth->assertAdmin($guest);
            $this->fail('guest should deny');
        } catch (PermissionDeniedException $e) {
            $this->addToAssertionCount(1);
        }

        $member = new User(10);
        $member->permissions = [ChatAuthorization::PERM_ENABLED => true];
        try {
            $auth->assertAdmin($member);
            $this->fail('member should deny');
        } catch (PermissionDeniedException $e) {
            $this->addToAssertionCount(1);
        }

        $suspended = new User(11);
        $suspended->isAdmin = true;
        $suspended->suspended_until = date('c', time() + 3600);
        try {
            $auth->assertAdmin($suspended);
            $this->fail('suspended admin should deny');
        } catch (PermissionDeniedException $e) {
            $this->addToAssertionCount(1);
        }

        $admin = new User(1);
        $admin->isAdmin = true;
        $auth->assertAdmin($admin);
        $this->addToAssertionCount(1);
    }

    public function testEgressProbeSuccessMock(): void
    {
        $probe = new RealtimeEgressProbe(null, fn () => ['status' => 200, 'error' => null]);
        $result = $probe->probe();
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['reachable']);
        $this->assertSame(200, $result['httpStatus']);
        $this->assertTrue($result['tlsVerified']);
        $this->assertArrayNotHasKey('category', $result);
        $blob = json_encode($result);
        $this->assertStringNotContainsString('Exception', $blob);
    }

    public function testEgressProbeUnexpectedStatus(): void
    {
        $probe = new RealtimeEgressProbe(null, fn () => ['status' => 500, 'error' => null]);
        $result = $probe->probe();
        $this->assertFalse($result['ok']);
        $this->assertSame('unexpected_status', $result['category']);
    }

    public function testEgressProbeTimeout(): void
    {
        $probe = new RealtimeEgressProbe(null, fn () => ['status' => null, 'error' => 'timeout']);
        $result = $probe->probe();
        $this->assertFalse($result['ok']);
        $this->assertSame('timeout', $result['category']);
        $this->assertStringNotContainsString('timed out', json_encode($result));
    }

    public function testEgressProbeTlsCategory(): void
    {
        $probe = new RealtimeEgressProbe(null, fn () => ['status' => null, 'error' => 'tls']);
        $result = $probe->probe();
        $this->assertSame('tls', $result['category']);
    }

    public function testEgressProbeRedirectClassified(): void
    {
        $probe = new RealtimeEgressProbe(null, fn () => ['status' => 302, 'error' => 'unexpected_status']);
        $result = $probe->probe();
        $this->assertFalse($result['ok']);
        $this->assertSame('unexpected_status', $result['category']);
    }

    public function testEgressProbeControllerAuthAndSsrfGuard(): void
    {
        $probe = new RealtimeEgressProbe(null, fn () => ['status' => 200, 'error' => null]);
        $controller = new RealtimeEgressProbeController($probe, new ChatAuthorization());

        $guestReq = (new ServerRequest('GET', '/api/flatrate-live-chat/realtime/egress-probe'))
            ->withAttribute('actor', new User(null));
        $guestResp = $controller->handle($guestReq);
        $this->assertSame(403, $guestResp->getStatusCode());

        $member = new User(10);
        $memberReq = (new ServerRequest('GET', '/api/flatrate-live-chat/realtime/egress-probe'))
            ->withAttribute('actor', $member);
        $memberResp = $controller->handle($memberReq);
        $this->assertSame(403, $memberResp->getStatusCode());

        $admin = new User(1);
        $admin->isAdmin = true;
        $ssrfReq = (new ServerRequest('GET', '/api/flatrate-live-chat/realtime/egress-probe?url=http://127.0.0.1'))
            ->withAttribute('actor', $admin)
            ->withQueryParams(['url' => 'http://127.0.0.1']);
        $ssrfResp = $controller->handle($ssrfReq);
        $this->assertSame(400, $ssrfResp->getStatusCode());
        $ssrfBody = json_decode((string) $ssrfResp->getBody(), true);
        $this->assertFalse($ssrfBody['ok']);
        $this->assertSame('internal', $ssrfBody['category']);
        $this->assertStringNotContainsString('127.0.0.1', (string) $ssrfResp->getBody());

        $okReq = (new ServerRequest('GET', '/api/flatrate-live-chat/realtime/egress-probe'))
            ->withAttribute('actor', $admin);
        $okResp = $controller->handle($okReq);
        $this->assertSame(200, $okResp->getStatusCode());
        $this->assertSame('no-store', $okResp->getHeaderLine('Cache-Control'));
        $okBody = json_decode((string) $okResp->getBody(), true);
        $this->assertTrue($okBody['ok']);
    }

    public function testFixedProbeTargetConstant(): void
    {
        $this->assertSame('https://realtime.flatrate.wiki/healthz', RealtimeEgressProbe::TARGET_URL);
    }
}
