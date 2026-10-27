<?php

namespace FlatRate\LiveChat\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;

class F2ActionAutoloadTest extends TestCase
{
    public function testEachF2ActionLoadsFromItsPsr4File(): void
    {
        $classes = [
            'FlatRate\\LiveChat\\F2\\Api\\ShowLiveRoomAction' => 'src/F2/Api/ShowLiveRoomAction.php',
            'FlatRate\\LiveChat\\F2\\Api\\ListLiveChatsAction' => 'src/F2/Api/ListLiveChatsAction.php',
            'FlatRate\\LiveChat\\F2\\Api\\SubscribeRoomAction' => 'src/F2/Api/SubscribeRoomAction.php',
            'FlatRate\\LiveChat\\F2\\Api\\UnsubscribeRoomAction' => 'src/F2/Api/UnsubscribeRoomAction.php',
            'FlatRate\\LiveChat\\F2\\Api\\FetchMessagesAction' => 'src/F2/Api/FetchMessagesAction.php',
            'FlatRate\\LiveChat\\F2\\Api\\PostMessageAction' => 'src/F2/Api/PostMessageAction.php',
            'FlatRate\\LiveChat\\F2\\Api\\EditMessageAction' => 'src/F2/Api/EditMessageAction.php',
            'FlatRate\\LiveChat\\F2\\Api\\DeleteMessageAction' => 'src/F2/Api/DeleteMessageAction.php',
            'FlatRate\\LiveChat\\F2\\Api\\MessageMutationResponder' => 'src/F2/Api/MessageMutationResponder.php',
        ];

        foreach ($classes as $class => $relative) {
            $this->assertTrue(class_exists($class), $class);
            $ref = new ReflectionClass($class);
            $this->assertSame(
                realpath(dirname(__DIR__).'/'.$relative),
                realpath($ref->getFileName())
            );
        }

        $handlers = [
            'FlatRate\\LiveChat\\F2\\Api\\ShowLiveRoomAction',
            'FlatRate\\LiveChat\\F2\\Api\\ListLiveChatsAction',
            'FlatRate\\LiveChat\\F2\\Api\\SubscribeRoomAction',
            'FlatRate\\LiveChat\\F2\\Api\\UnsubscribeRoomAction',
            'FlatRate\\LiveChat\\F2\\Api\\FetchMessagesAction',
            'FlatRate\\LiveChat\\F2\\Api\\PostMessageAction',
            'FlatRate\\LiveChat\\F2\\Api\\EditMessageAction',
            'FlatRate\\LiveChat\\F2\\Api\\DeleteMessageAction',
        ];

        foreach ($handlers as $class) {
            $ref = new ReflectionClass($class);
            $this->assertTrue($ref->implementsInterface(RequestHandlerInterface::class), $class);
            $this->assertTrue($ref->isFinal(), $class);
            $this->assertFalse($ref->getParentClass(), $class.' must not extend another action');
        }

        $this->assertFileDoesNotExist(dirname(__DIR__).'/src/F2/Api/LiveRoomActions.php');
    }
}
