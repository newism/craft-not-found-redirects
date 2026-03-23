<?php

namespace newism\notfoundredirects\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use Illuminate\Support\Collection;
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

        $this->save($model);

        return $model;
    }

    /**
     * @return Collection<int, Note>
     */
    public function findByRedirectId(int $redirectId): Collection
    {
        $rows = (new Query())
            ->from('{{%notfoundredirects_notes}}')
            ->where(['redirectId' => $redirectId])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();

        return Collection::make($rows)->map(fn(array $row) => Note::fromRow($row));
    }

    public function findById(int $id): ?Note
    {
        $row = (new Query())
            ->from('{{%notfoundredirects_notes}}')
            ->where(['id' => $id])
            ->one();

        return $row ? Note::fromRow($row) : null;
    }

    public function save(Note $model): bool
    {
        if (!$model->validate()) {
            return false;
        }

        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $attributes = [
            'redirectId' => $model->redirectId,
            'note' => $model->note,
            'systemGenerated' => $model->systemGenerated,
            'createdById' => $model->createdById,
        ];

        if ($model->id) {
            $attributes['dateUpdated'] = $now;
            $db->createCommand()->update(
                '{{%notfoundredirects_notes}}',
                $attributes,
                ['id' => $model->id],
            )->execute();
        } else {
            $attributes['dateCreated'] = $now;
            $attributes['dateUpdated'] = $now;
            if (!$model->createdById && !$model->systemGenerated) {
                $user = Craft::$app->getUser()->getIdentity();
                $attributes['createdById'] = $user?->id;
                $model->createdById = $attributes['createdById'];
            }
            $db->createCommand()->insert(
                '{{%notfoundredirects_notes}}',
                $attributes,
            )->execute();
            $model->id = (int) $db->getLastInsertID();
        }

        Craft::info("Note saved: #{$model->id} for redirect #{$model->redirectId}", NotFoundRedirects::LOG);

        return true;
    }

    public function deleteById(int $id): bool
    {
        $rows = Craft::$app->getDb()->createCommand()
            ->delete('{{%notfoundredirects_notes}}', ['id' => $id])
            ->execute();

        if ($rows > 0) {
            Craft::info("Note deleted: #{$id}", NotFoundRedirects::LOG);
        }

        return $rows > 0;
    }
}
