<?php

namespace newism\notfoundredirects\gql\resolvers;

use craft\gql\base\Resolver;
use GraphQL\Type\Definition\ResolveInfo;
use newism\notfoundredirects\query\NotFoundUriQuery;
use newism\notfoundredirects\query\RedirectQuery;

class RedirectsResolver extends Resolver
{
    public static function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        return null;
    }

    public static function resolveNotFounds(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): array
    {
        $query = NotFoundUriQuery::find();
        $query->handled = $arguments['handled'] ?? null;

        return $query
            ->limit($arguments['limit'] ?? 100)
            ->offset($arguments['offset'] ?? 0)
            ->all();
    }

    public static function resolveNotFound(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        $id = $arguments['id'] ?? null;
        $uri = $arguments['uri'] ?? null;

        if ($id) {
            $query = NotFoundUriQuery::find();
            $query->id = $id;
            return $query->one();
        }

        if ($uri) {
            $query = NotFoundUriQuery::find();
            $query->search = $uri;
            return $query->limit(1)->one();
        }

        return null;
    }

    public static function resolveRedirects(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): array
    {
        $query = RedirectQuery::find();

        return $query
            ->limit($arguments['limit'] ?? 100)
            ->offset($arguments['offset'] ?? 0)
            ->all();
    }

    public static function resolveRedirect(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        $id = $arguments['id'] ?? null;

        if (!$id) {
            return null;
        }

        $query = RedirectQuery::find();
        $query->id = $id;
        return $query->one();
    }
}
