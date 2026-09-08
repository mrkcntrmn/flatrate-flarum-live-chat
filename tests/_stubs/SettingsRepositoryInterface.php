<?php
namespace Flarum\Settings;
interface SettingsRepositoryInterface {
    public function get($key, $default = null);
}
