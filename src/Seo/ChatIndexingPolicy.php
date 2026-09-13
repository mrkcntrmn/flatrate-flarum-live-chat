<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Seo;

final class ChatIndexingPolicy
{
    /**
     * Chat surfaces stay noindex. Ordinary forum routes must not inherit that directive.
     */
    public static function shouldNoIndexPath(string $path): bool
    {
        $normalized = self::normalizePath($path);

        return $normalized === '/chat'
            || $normalized === '/live'
            || str_starts_with($normalized, '/live/');
    }

    public static function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        if ($path[0] !== '/') {
            $path = '/'.$path;
        }

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }
}
