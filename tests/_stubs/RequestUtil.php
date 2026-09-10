<?php
namespace Flarum\Http;

use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

class RequestUtil
{
    public static function getActor(ServerRequestInterface $request): User
    {
        $actor = $request->getAttribute('actor');
        if ($actor instanceof User) {
            return $actor;
        }

        return new User(null);
    }
}
