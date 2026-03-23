<?php

namespace newism\notfoundredirects\gql\interfaces;

use craft\gql\GqlEntityRegistry;
use craft\gql\TypeManager;
use GraphQL\Type\Definition\Type;
use newism\notfoundredirects\gql\types\RedirectType;

/**
 * Defines and registers the NotFoundRedirectsRedirect GQL type.
 *
 * Not a true GraphQL interface (our types are simple, non-element) —
 * but follows Craft's convention of centralising field definitions
 * and type registration in an "interface" class.
 */
class RedirectInterface
{
    public static function getName(): string
    {
        return 'NotFoundRedirectsRedirect';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new RedirectType([
            'name' => self::getName(),
            'fields' => self::class . '::getFieldDefinitions',
        ]));
    }

    public static function getFieldDefinitions(): array
    {
        return TypeManager::prepareFieldDefinitions([
            'id' => [
                'name' => 'id',
                'type' => Type::int(),
                'description' => 'The redirect ID.',
            ],
            'siteId' => [
                'name' => 'siteId',
                'type' => Type::int(),
                'description' => 'The site ID (null = all sites).',
            ],
            'from' => [
                'name' => 'from',
                'type' => Type::string(),
                'description' => 'The source URI pattern.',
            ],
            'to' => [
                'name' => 'to',
                'type' => Type::string(),
                'description' => 'The destination URI.',
            ],
            'toType' => [
                'name' => 'toType',
                'type' => Type::string(),
                'description' => 'Destination type: url or entry.',
            ],
            'toElementId' => [
                'name' => 'toElementId',
                'type' => Type::int(),
                'description' => 'Destination entry ID (when toType is "entry").',
            ],
            'statusCode' => [
                'name' => 'statusCode',
                'type' => Type::int(),
                'description' => 'HTTP status code (301, 302, 307, 410).',
            ],
            'priority' => [
                'name' => 'priority',
                'type' => Type::int(),
                'description' => 'Priority (higher = checked first).',
            ],
            'enabled' => [
                'name' => 'enabled',
                'type' => Type::boolean(),
                'description' => 'Whether the redirect is active.',
            ],
            'startDate' => [
                'name' => 'startDate',
                'type' => Type::string(),
                'description' => 'Active from date (ISO 8601).',
            ],
            'endDate' => [
                'name' => 'endDate',
                'type' => Type::string(),
                'description' => 'Active until date (ISO 8601).',
            ],
            'systemGenerated' => [
                'name' => 'systemGenerated',
                'type' => Type::boolean(),
                'description' => 'Whether this redirect was system-generated.',
            ],
            'elementId' => [
                'name' => 'elementId',
                'type' => Type::int(),
                'description' => 'The source element ID (for auto-created redirects).',
            ],
            'createdById' => [
                'name' => 'createdById',
                'type' => Type::int(),
                'description' => 'The ID of the user who created this redirect.',
            ],
            'hitCount' => [
                'name' => 'hitCount',
                'type' => Type::int(),
                'description' => 'Number of times this redirect was used.',
            ],
            'hitLastTime' => [
                'name' => 'hitLastTime',
                'type' => Type::string(),
                'description' => 'Last redirect time (ISO 8601).',
            ],
            'dateCreated' => [
                'name' => 'dateCreated',
                'type' => Type::string(),
                'description' => 'Created date (ISO 8601).',
            ],
            'dateUpdated' => [
                'name' => 'dateUpdated',
                'type' => Type::string(),
                'description' => 'Updated date (ISO 8601).',
            ],
        ], self::getName());
    }
}
