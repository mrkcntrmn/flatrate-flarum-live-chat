<?php
namespace Flarum\Database;
class AbstractModel
{
    /** @var array<string,mixed> */
    private array $attrs = [];

    public function __get($k)
    {
        return $this->attrs[$k] ?? null;
    }

    public function __set($k, $v)
    {
        $this->attrs[$k] = $v;
    }

    public function __isset($k)
    {
        return array_key_exists($k, $this->attrs);
    }
}
