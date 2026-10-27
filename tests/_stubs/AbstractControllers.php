<?php

namespace Flarum\Api\Controller;

/**
 * Test stand-in for the Flarum 1 controllers removed in Flarum 2.
 * Production Flarum 2 requests use src/F2/Api instead of these parents.
 */
abstract class AbstractShowController
{
}

abstract class AbstractListController
{
    protected function extractInclude($request): array
    {
        $include = $request->getQueryParams()['include'] ?? '';
        if ($include === null || $include === '') {
            return [];
        }

        return is_array($include) ? $include : explode(',', (string) $include);
    }
}
