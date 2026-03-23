<?php

namespace newism\notfoundredirects\gql\resolvers;

use craft\gql\base\Resolver;
use GraphQL\Type\Definition\ResolveInfo;
use newism\notfoundredirects\NotFoundRedirects;

class RedirectsResolver extends Resolver
{
    public static function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        return null;
    }

    public static function resolveNotFounds(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): array
    {
        return NotFoundRedirects::getInstance()->notFound->find(
            handled: $arguments['handled'] ?? null,
            limit: $arguments['limit'] ?? 100,
            offset: $arguments['offset'] ?? 0,
        )->all();
    }

    public static function resolveNotFound(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        $id = $arguments['id'] ?? null;
        $uri = $arguments['uri'] ?? null;

        if ($id) {
            return NotFoundRedirects::getInstance()->notFound->findById($id);
        }

        if ($uri) {
            return NotFoundRedirects::getInstance()->notFound->find(search: $uri, limit: 1)->first();
        }

        return null;
    }

    public static function resolveRedirects(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): array
    {
        return NotFoundRedirects::getInstance()->redirects->find(
            limit: $arguments['limit'] ?? 100,
            offset: $arguments['offset'] ?? 0,
        )->all();
    }

    public static function resolveRedirect(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        $id = $arguments['id'] ?? null;

        return $id ? NotFoundRedirects::getInstance()->redirects->findById($id) : null;
    }
}
