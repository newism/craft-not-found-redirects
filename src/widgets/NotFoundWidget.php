<?php

namespace newism\notfoundredirects\widgets;

use Craft;
use craft\base\Widget;
use craft\enums\Color;
use craft\helpers\Cp;
use newism\notfoundredirects\db\Table;
use newism\notfoundredirects\models\NotFoundUri;
use newism\notfoundredirects\query\NotFoundUriQuery;

class NotFoundWidget extends Widget
{
    public string $view = 'latest';
    public string $handled = 'unhandled';
    public int $limit = 10;

    public static function displayName(): string
    {
        return Craft::t('not-found-redirects', '404 Redirects');
    }

    public static function icon(): ?string
    {
        return 'diamond-turn-right';
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['limit'], 'number', 'integerOnly' => true];
        $rules[] = [['view'], 'in', 'range' => ['latest', 'top']];
        $rules[] = [['handled'], 'in', 'range' => ['all', 'unhandled', 'handled']];
        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'not-found-redirects/_widgets/not-found/settings',
            ['widget' => $this],
        );
    }

    public function getTitle(): ?string
    {
        return match ($this->view) {
            'top' => Craft::t('not-found-redirects', 'Top 404s'),
            default => Craft::t('not-found-redirects', 'Latest 404s'),
        };
    }

    public function getSubtitle(): ?string
    {
        $statusLabel = match ($this->handled) {
            'handled' => Craft::t('not-found-redirects', 'Handled'),
            'unhandled' => Craft::t('not-found-redirects', 'Unhandled'),
            default => Craft::t('not-found-redirects', 'All'),
        };

        return $statusLabel . ' · ' . match ($this->view) {
                'top' => Craft::t('not-found-redirects', 'by total hit count'),
                default => Craft::t('not-found-redirects', 'by last seen'),
            };
    }

    public function getBodyHtml(): ?string
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can('not-found-redirects:view404s')) {
            return null;
        }

        $sortField = match ($this->view) {
            'top' => Table::NOT_FOUND_URIS . '.[[hitCount]]',
            default => Table::NOT_FOUND_URIS . '.[[hitLastTime]]',
        };

        $query = NotFoundUriQuery::find();
        $query->handled = match ($this->handled) {
            'handled' => true,
            'unhandled' => false,
            default => null,
        };

        $items = $query
            ->orderBy([$sortField => SORT_DESC])
            ->limit($this->limit)
            ->collect();

        $handledHtmlMap = [];
        foreach ($items as $item) {
            $handledHtmlMap[$item->id] = Cp::statusLabelHtml([
                'color' => $item->handled ? Color::Teal : Color::Red,
                'icon' => $item->handled ? 'check' : 'xmark',
                'label' => $item->handled ? Craft::t('app', 'Yes') : Craft::t('app', 'No'),
            ]);
        }

        return Craft::$app->getView()->renderTemplate(
            'not-found-redirects/_widgets/not-found/body',
            [
                'items' => $items,
                'view' => $this->view,
                'handledHtmlMap' => $handledHtmlMap,
            ],
        );
    }
}
