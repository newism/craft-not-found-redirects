<?php

namespace newism\notfoundredirects\models;

use craft\base\Model;
use newism\notfoundredirects\actions\ImportAction;
use yii\web\UploadedFile;

class ImportForm extends Model
{
    public ?UploadedFile $file = null;
    public ?string $inputSource = null;

    public ?ImportResult $result = null;

    protected function defineRules(): array
    {
        return [
            [['file'], 'required'],
            [['file'], 'file', 'extensions' => ['csv', 'json'], 'checkExtensionByMimeType' => false],
            [['inputSource'], 'in', 'range' => [ImportAction::SOURCE_PLUGIN, ImportAction::SOURCE_RETOUR, null]],
        ];
    }

    public function hasResults(): bool
    {
        return $this->result !== null;
    }
}
