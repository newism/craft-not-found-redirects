<?php

namespace newism\notfoundredirects\helpers;

use craft\helpers\Gql as BaseGqlHelper;

class Gql extends BaseGqlHelper
{
    public static function canQueryRedirects(): bool
    {
        $allowedEntities = self::extractAllowedEntitiesFromSchema();

        return isset($allowedEntities['not-found-redirects']);
    }
}
