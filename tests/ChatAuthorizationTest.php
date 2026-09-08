<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Chat;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use PHPUnit\Framework\TestCase;

class ChatAuthorizationTest extends TestCase
{
    private ChatAuthorization $auth;

    protected function setUp(): void
    {
        $this->auth = new ChatAuthorization();
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

    private function publicRoom(string $roomKey = 'toyota-live'): Chat
    {
        $chat = new Chat();
        $chat->type = 1;
        $chat->room_key = $roomKey;
        $chat->scope_type = 'board';
        $chat->scope_key = 'toyota';
        return $chat;
    }

    public function testGuestPostRejected(): void
    {
        $guest = new User(null);
        $this->expectException(PermissionDeniedException::class);
        $this->auth->assertCanPost($guest, $this->publicRoom());
    }

    public function testSuspendedMemberPostRejected(): void
    {
        $u = $this->member();
        $u->suspended_until = date('c', time() + 3600);
        $this->expectException(PermissionDeniedException::class);
        $this->auth->assertCanPost($u, $this->publicRoom());
    }

    public function testMemberRoomCreateRejected(): void
    {
        $u = $this->member();
        $this->expectException(PermissionDeniedException::class);
        $this->auth->assertMemberCannotMutateRooms($u);
    }

    public function testMemberRoomRenameRejected(): void
    {
        $u = $this->member();
        $this->expectException(PermissionDeniedException::class);
        $this->auth->assertMemberCannotMutateRooms($u);
    }

    public function testSenderSpoofRejected(): void
    {
        $u = $this->member();
        $this->expectException(PermissionDeniedException::class);
        $this->auth->resolveSenderId($u, ['user_id' => 999]);
    }

    public function testSenderDerivedFromActor(): void
    {
        $u = $this->member();
        $this->assertSame(10, $this->auth->resolveSenderId($u, ['message' => 'hi']));
    }

    public function testGuestMessageHistoryRejected(): void
    {
        $guest = new User(null);
        $guest->permissions = [ChatAuthorization::PERM_ENABLED => true];
        $this->expectException(PermissionDeniedException::class);
        $this->auth->assertCanReadMessages($guest, $this->publicRoom());
    }

    public function testPrivateTypeRejectedOnRead(): void
    {
        $u = $this->member();
        $chat = $this->publicRoom();
        $chat->type = 0;
        $this->expectException(PermissionDeniedException::class);
        $this->auth->assertCanReadRoom($u, $chat);
    }

    public function testType0CreationPathDenied(): void
    {
        $this->expectException(PermissionDeniedException::class);
        $this->auth->assertPrivateChatDisabled();
    }

    public function testEnabledPermissionRequired(): void
    {
        $u = new User(10);
        $u->permissions = [ChatAuthorization::PERM_CHAT => true]; // missing enabled
        $this->expectException(PermissionDeniedException::class);
        $this->auth->assertCanPost($u, $this->publicRoom());
    }

    public function testAdminCanManageRooms(): void
    {
        $admin = new User(1);
        $admin->isAdmin = true;
        $this->auth->assertCanManageCanonicalRooms($admin);
        $this->addToAssertionCount(1);
    }
}
