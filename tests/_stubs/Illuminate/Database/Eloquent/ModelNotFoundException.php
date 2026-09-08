<?php
namespace Illuminate\Database\Eloquent;
class ModelNotFoundException extends \RuntimeException
{
    public function setModel($model, $ids = [])
    {
        return $this;
    }
}
