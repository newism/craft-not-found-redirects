<?php

namespace newism\notfoundredirects\gql\queries;

use craft\gql\base\Query;
use GraphQL\Type\Definition\Type;
use newism\notfoundredirects\gql\interfaces\NotFoundInterface;
use newism\notfoundredirects\gql\interfaces\RedirectInterface;
use newism\notfoundredirects\gql\resolvers\RedirectsResolver;
use newism\notfoundredirects\helpers\Gql as GqlHelper;

class RedirectsQuery extends Query
{
    public static function getQueries($checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canQueryRedirects()) {
            return [];
        }

        return [
            'notFoundRedirects404s' => [
                'type' => Type::listOf(NotFoundInterface::getType()),
                'args' => [
                    'handled' => [
                        'name' => 'handled',
                        'type' => Type::boolean(),
                        'description' => 'Filter by handled status. Omit for all.',
                    ],
                    'limit' => [
                        'name' => 'limit',
                        'type' => Type::int(),
                        'description' => 'Maximum number of results. Default: 100.',
                    ],
                    'offset' => [
                        'name' => 'offset',
                        'type' => Type::int(),
                        'description' => 'Number of results to skip.',
                    ],
                ],
                'resolve' => RedirectsResolver::class . '::resolveNotFounds',
                'description' => 'Query all 404 records.',
            ],
            'notFoundRedirects404' => [
                'type' => NotFoundInterface::getType(),
                'args' => [
                    'id' => [
                        'name' => 'id',
                        'type' => Type::int(),
                        'description' => 'The 404 record ID.',
                    ],
                    'uri' => [
                        'name' => 'uri',
                        'type' => Type::string(),
                        'description' => 'The 404 URI to search for.',
                    ],
                ],
                'resolve' => RedirectsResolver::class . '::resolveNotFound',
                'description' => 'Query a single 404 record by ID or URI.',
            ],
            'notFoundRedirectsRedirects' => [
                'type' => Type::listOf(RedirectInterface::getType()),
                'args' => [
                    'limit' => [
                        'name' => 'limit',
                        'type' => Type::int(),
                        'description' => 'Maximum number of results. Default: 100.',
                    ],
                    'offset' => [
                        'name' => 'offset',
                        'type' => Type::int(),
                        'description' => 'Number of results to skip.',
                    ],
                ],
                'resolve' => RedirectsResolver::class . '::resolveRedirects',
                'description' => 'Query all redirect rules.',
            ],
            'notFoundRedirectsRedirect' => [
                'type' => RedirectInterface::getType(),
                'args' => [
                    'id' => [
                        'name' => 'id',
                        'type' => Type::int(),
                        'description' => 'The redirect ID.',
                    ],
                ],
                'resolve' => RedirectsResolver::class . '::resolveRedirect',
                'description' => 'Query a single redirect rule by ID.',
            ],
        ];
    }
}
