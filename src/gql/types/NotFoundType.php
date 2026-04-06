<?php

namespace newism\notfoundredirects\gql\types;

use craft\gql\base\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use newism\notfoundredirects\models\NotFoundUri;

class NotFoundType extends ObjectType
{
    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var NotFoundUri $source */
        return match ($resolveInfo->fieldName) {
            'id' => $source->id,
            'siteId' => $source->siteId,
            'uri' => $source->uri,
            'hitCount' => $source->hitCount,
            'hitLastTime' => $source->hitLastTime?->format('c'),
            'handled' => $source->handled,
            'redirectId' => $source->redirectId,
            'referrerCount' => $source->referrerCount,
            'dateCreated' => $source->dateCreated?->format('c'),
            'dateUpdated' => $source->dateUpdated?->format('c'),
            default => null,
        };
    }
}
