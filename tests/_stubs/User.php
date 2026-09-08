<?php
namespace Flarum\User;
use Flarum\User\Exception\PermissionDeniedException;
class User {
    public $id;
    public $suspended_until = null;
    public $isAdmin = false;
    public $permissions = [];
    public function __construct($id = null) { $this->id = $id; }
    public function isAdmin(): bool { return (bool) $this->isAdmin; }
    public function isSuspended(): bool {
        if ($this->suspended_until) {
            $ts = strtotime((string) $this->suspended_until);
            return $ts && $ts > time();
        }
        return false;
    }
    public function can($permission): bool {
        if ($this->isAdmin) return true;
        return !empty($this->permissions[$permission]);
    }
    public function assertCan($permission): void {
        if (!$this->can($permission)) throw new PermissionDeniedException((string)$permission);
    }
    public function assertPermission($condition): void {
        if (!$condition) throw new PermissionDeniedException('permission');
    }
}
