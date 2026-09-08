<?php
namespace Flarum\Foundation;
class AbstractServiceProvider {
    protected $container;
    public function __construct($container = null) { $this->container = $container; }
}
