<?php
namespace Flarum\Api\Serializer;
class UserSerializer extends AbstractSerializer {
    protected function getDefaultAttributes($user) { return ['id' => $user->id ?? null]; }
}
