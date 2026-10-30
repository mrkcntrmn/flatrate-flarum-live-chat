<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\F2\Api\ChatMessageJson;
use PHPUnit\Framework\TestCase;

final class F2ChatMessageUserTest extends TestCase
{
    public function testCanonicalSlugIsIncludedWithoutPrivateUserFields(): void
    {
        $user = (object) ['id' => 42, 'username' => 'member', 'display_name' => 'Member', 'avatar_url' => null, 'email' => 'private@example.invalid'];
        $resource = ChatMessageJson::includedUser($user, '42-member');
        self::assertSame('42-member', $resource['attributes']['slug']);
        self::assertSame(['username', 'slug', 'displayName', 'avatarUrl'], array_keys($resource['attributes']));
        self::assertArrayNotHasKey('email', $resource['attributes']);
        self::assertSame('42', $resource['id']);
        self::assertNull(ChatMessageJson::includedUser(null, ''));
    }
}
