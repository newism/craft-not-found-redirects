<?php

namespace newism\notfoundredirects\services;

use Craft;
use craft\base\Component;
use newism\notfoundredirects\db\Table;
use newism\notfoundredirects\models\Note;
use newism\notfoundredirects\NotFoundRedirects;

class NoteService extends Component
{
    public function addNote(int $redirectId, string $note, bool $systemGenerated = false): Note
    {
        $model = new Note();
        $model->redirectId = $redirectId;
        $model->note = $note;
        $model->systemGenerated = $systemGenerated;

        if (!$systemGenerated) {
            $user = Craft::$app->getUser()->getIdentity();
            $model->createdById = $user?->id;
        }

        $this->saveNote($model);

        return $model;
    }

    public function saveNote(Note $model): bool
    {
        if (!$model->validate()) {
            return false;
        }

        $db = Craft::$app->getDb();

        $attributes = [
            'redirectId' => $model->redirectId,
            'note' => $model->note,
            'systemGenerated' => $model->systemGenerated,
            'createdById' => $model->createdById,
        ];

        if ($model->id) {
            $db->createCommand()->update(
                Table::NOTES,
                $attributes,
                ['id' => $model->id],
            )->execute();
        } else {
            if (!$model->createdById && !$model->systemGenerated) {
                $user = Craft::$app->getUser()->getIdentity();
                $attributes['createdById'] = $user?->id;
                $model->createdById = $attributes['createdById'];
            }
            $db->createCommand()->insert(
                Table::NOTES,
                $attributes,
            )->execute();
            $model->id = (int)$db->getLastInsertID();
        }

        Craft::debug("Note saved: #{$model->id} for redirect #{$model->redirectId}", NotFoundRedirects::LOG);

        return true;
    }

    public function deleteNoteById(int $id): bool
    {
        $rows = Craft::$app->getDb()->createCommand()
            ->delete(Table::NOTES, ['id' => $id])
            ->execute();

        if ($rows > 0) {
            Craft::debug("Note deleted: #{$id}", NotFoundRedirects::LOG);
        }

        return $rows > 0;
    }
}
