<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Realtime;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\ChatRepository;
use FlatRate\LiveChat\Observability\RealtimeLogger;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Pusher\Pusher;

/**
 * Signs private channel auth only after actor + room + rollout ACL pass.
 */
class PusherChannelAuthorizer
{
    public function __construct(
        private PusherClientConfig $config,
        private PerRoomChannelNamer $channels,
        private ChatRepository $chats,
        private ChatAuthorization $auth,
        private ?RealtimeLogger $logger = null
    ) {
        $this->logger = $logger ?? new RealtimeLogger();
    }

    /**
     * @return array{auth:string}
     */
    public function authorize(User $actor, string $socketId, string $channelName): array
    {
        if (!$this->config->isComplete()) {
            $this->logger->warning('realtime.auth.deny', [
                'reason' => 'credentials_incomplete',
                'userId' => $actor->id,
            ]);
            throw new PermissionDeniedException();
        }

        if (!$actor->id) {
            $this->logger->warning('realtime.auth.deny', ['reason' => 'guest', 'channel' => $this->redactChannel($channelName)]);
            throw new PermissionDeniedException();
        }

        if (!str_starts_with($channelName, 'private-flatrate-live-')) {
            $this->logger->warning('realtime.auth.deny', [
                'reason' => 'invalid_channel_prefix',
                'userId' => $actor->id,
            ]);
            throw new PermissionDeniedException();
        }

        $roomKey = $this->channels->roomKeyFromChannel($channelName);
        if ($roomKey === null) {
            // Unknown/guessed channel — prefer not found for ordinary members.
            $this->logger->warning('realtime.auth.deny', [
                'reason' => 'unknown_channel',
                'userId' => $actor->id,
            ]);
            throw new ModelNotFoundException();
        }

        try {
            $chat = $this->chats->findByRoomKeyOrFail($roomKey, $actor);
        } catch (ModelNotFoundException $e) {
            $this->logger->warning('realtime.auth.deny', [
                'reason' => 'room_not_visible',
                'userId' => $actor->id,
                'roomKey' => $roomKey,
            ]);
            throw $e;
        }

        // Subscription requires message-read ACL (guests already denied).
        $this->auth->assertCanSubscribeRealtime($actor, $chat);
        $expected = $this->channels->channelKeyForRoom($chat);
        if (!hash_equals($expected, $channelName)) {
            $this->logger->warning('realtime.auth.deny', [
                'reason' => 'channel_mismatch',
                'userId' => $actor->id,
                'roomKey' => $roomKey,
            ]);
            throw new PermissionDeniedException();
        }

        $pusher = new Pusher(
            (string) $this->config->appKey(),
            (string) $this->config->appSecret(),
            (string) $this->config->appId(),
            ['cluster' => $this->config->cluster() ?: 'mt1', 'useTLS' => true]
        );

        $authJson = $pusher->authorizeChannel($channelName, $socketId);
        $decoded = json_decode($authJson, true);
        if (!is_array($decoded) || empty($decoded['auth'])) {
            $this->logger->warning('realtime.auth.deny', [
                'reason' => 'sign_failed',
                'userId' => $actor->id,
                'roomKey' => $roomKey,
            ]);
            throw new PermissionDeniedException();
        }

        $this->logger->info('realtime.auth.allow', [
            'userId' => $actor->id,
            'roomKey' => $roomKey,
        ]);

        return ['auth' => (string) $decoded['auth']];
    }

    private function redactChannel(string $channelName): string
    {
        return substr($channelName, 0, 28) . '…';
    }
}
