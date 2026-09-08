<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Commands\CreateChat;
use FlatRate\LiveChat\Commands\CreateChatHandler;
use FlatRate\LiveChat\Commands\DeleteChat;
use FlatRate\LiveChat\Commands\DeleteChatHandler;
use FlatRate\LiveChat\Commands\EditChat;
use FlatRate\LiveChat\Commands\EditChatHandler;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use PHPUnit\Framework\TestCase;

class PrivateChatAndMutationDisableTest extends TestCase
{
    private function member(): User
    {
        $u = new User(5);
        $u->permissions = [
            ChatAuthorization::PERM_ENABLED => true,
            ChatAuthorization::PERM_CHAT => true,
            'flatrate-live-chat.permissions.create' => true,
            'flatrate-live-chat.permissions.create.channel' => true,
        ];
        return $u;
    }

    public function testType0PrivateChatCreationRejected(): void
    {
        $handler = new CreateChatHandler(new ChatAuthorization());
        $cmd = new CreateChat($this->member(), [
            'attributes' => ['isChannel' => 0, 'title' => 'pm'],
        ], null);
        $this->expectException(PermissionDeniedException::class);
        $handler->handle($cmd);
    }

    public function testMemberChannelCreateRejectedEvenWithLegacyPermission(): void
    {
        $handler = new CreateChatHandler(new ChatAuthorization());
        $cmd = new CreateChat($this->member(), [
            'attributes' => ['isChannel' => 1, 'title' => 'rogue'],
        ], null);
        $this->expectException(PermissionDeniedException::class);
        $handler->handle($cmd);
    }

    public function testMemberRenameRejected(): void
    {
        $handler = new EditChatHandler(new ChatAuthorization());
        $cmd = new EditChat(1, $this->member(), ['attributes' => ['title' => 'x']], '');
        $this->expectException(PermissionDeniedException::class);
        $handler->handle($cmd);
    }

    public function testMemberDeleteRejected(): void
    {
        $handler = new DeleteChatHandler(new ChatAuthorization());
        $cmd = new DeleteChat(1, $this->member());
        $this->expectException(PermissionDeniedException::class);
        $handler->handle($cmd);
    }

    public function testScopeMutationRejectedForAdminViaEditApi(): void
    {
        $admin = new User(1);
        $admin->isAdmin = true;
        $handler = new EditChatHandler(new ChatAuthorization());
        $cmd = new EditChat(1, $admin, ['attributes' => ['room_key' => 'hijack']], '');
        $this->expectException(PermissionDeniedException::class);
        $handler->handle($cmd);
    }
}
