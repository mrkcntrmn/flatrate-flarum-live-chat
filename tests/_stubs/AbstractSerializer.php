<?php
namespace Flarum\Api\Serializer;
class AbstractSerializer {
    protected function formatDate($date) { return $date; }
    protected function getActor() { return (object)['id' => null]; }
    protected function hasOne($m, $c) { return null; }
    protected function hasMany($m, $c) { return null; }
}
