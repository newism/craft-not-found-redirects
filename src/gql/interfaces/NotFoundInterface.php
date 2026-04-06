<?php

namespace newism\notfoundredirects\gql\interfaces;

use craft\gql\GqlEntityRegistry;
use craft\gql\TypeManager;
use GraphQL\Type\Definition\Type;
use newism\notfoundredirects\gql\types\NotFoundType;

/**
 * Defines and registers the NotFoundRedirects404 GQL type.
 *
 * Not a true GraphQL interface (our types are simple, non-element) —
 * but follows Craft's convention of centralising field definitions
 * and type registration in an "interface" class.
 */
class NotFoundInterface
{
    public static function getName(): string
    {
        return 'NotFoundRedirects404';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new NotFoundType([
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
                'description' => 'The 404 record ID.',
            ],
            'siteId' => [
                'name' => 'siteId',
                'type' => Type::int(),
                'description' => 'The site ID.',
            ],
            'uri' => [
                'name' => 'uri',
                'type' => Type::string(),
                'description' => 'The normalized 404 URI.',
            ],
            'hitCount' => [
                'name' => 'hitCount',
                'type' => Type::int(),
                'description' => 'Number of times this 404 was hit.',
            ],
            'hitLastTime' => [
                'name' => 'hitLastTime',
                'type' => Type::string(),
                'description' => 'Last time this 404 was hit (ISO 8601).',
            ],
            'handled' => [
                'name' => 'handled',
                'type' => Type::boolean(),
                'description' => 'Whether this 404 is handled by a redirect.',
            ],
            'redirectId' => [
                'name' => 'redirectId',
                'type' => Type::int(),
                'description' => 'The ID of the redirect handling this 404.',
            ],
            'referrerCount' => [
                'name' => 'referrerCount',
                'type' => Type::int(),
                'description' => 'Number of unique referrers.',
            ],
            'dateCreated' => [
                'name' => 'dateCreated',
                'type' => Type::string(),
                'description' => 'First seen date (ISO 8601).',
            ],
            'dateUpdated' => [
                'name' => 'dateUpdated',
                'type' => Type::string(),
                'description' => 'Last updated date (ISO 8601).',
            ],
        ], self::getName());
    }
}
