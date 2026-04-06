<?php

namespace newism\notfoundredirects\gql\types;

use craft\gql\base\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use newism\notfoundredirects\models\Redirect;

class RedirectType extends ObjectType
{
    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var Redirect $source */
        return match ($resolveInfo->fieldName) {
            'id' => $source->id,
            'siteId' => $source->siteId,
            'from' => $source->from,
            'to' => $source->to,
            'toType' => $source->toType,
            'toElementId' => $source->toElementId,
            'statusCode' => $source->statusCode,
            'priority' => $source->priority,
            'enabled' => $source->enabled,
            'regexMatch' => $source->regexMatch,
            'startDate' => $source->startDate?->format('c'),
            'endDate' => $source->endDate?->format('c'),
            'systemGenerated' => $source->systemGenerated,
            'elementId' => $source->elementId,
            'createdById' => $source->createdById,
            'hitCount' => $source->hitCount,
            'hitLastTime' => $source->hitLastTime?->format('c'),
            'dateCreated' => $source->dateCreated?->format('c'),
            'dateUpdated' => $source->dateUpdated?->format('c'),
            default => null,
        };
    }
}
