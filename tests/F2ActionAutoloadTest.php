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
        ];

        foreach ($classes as $class => $relative) {
            $this->assertTrue(class_exists($class), $class);
            $ref = new ReflectionClass($class);
            $this->assertSame(
                realpath(dirname(__DIR__).'/'.$relative),
                realpath($ref->getFileName())
            );
            $this->assertTrue($ref->implementsInterface(RequestHandlerInterface::class));
            $parent = $ref->getParentClass();
            if ($parent) {
                $this->assertTrue(class_exists($parent->getName()));
                $this->assertFalse($parent->isFinal(), $parent->getName());
            }
        }

        $delete = new ReflectionClass('FlatRate\\LiveChat\\F2\\Api\\DeleteMessageAction');
        $this->assertSame('FlatRate\\LiveChat\\F2\\Api\\EditMessageAction', $delete->getParentClass()->getName());
        $this->assertFalse($delete->getParentClass()->isFinal());
    }
}
