<?php

namespace newism\notfoundredirects\controllers;

use Craft;
use craft\web\Controller;
use newism\notfoundredirects\models\Note;
use newism\notfoundredirects\NotFoundRedirects;
use newism\notfoundredirects\query\NoteQuery;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class NotesController extends Controller
{
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function actionEdit(?int $noteId = null, ?int $redirectId = null): Response
    {
        $this->requirePermission('not-found-redirects:manageRedirects');

        // Support both route params and query params (slideouts use action URLs)
        $noteId = $noteId ?? $this->request->getParam('noteId');
        $redirectId = $redirectId ?? $this->request->getParam('redirectId');

        if ($noteId) {
            $query = NoteQuery::find();
            $query->andWhere(['id' => (int)$noteId]);
            $note = $query->one();
            if (!$note) {
                throw new NotFoundHttpException('Note not found.');
            }
            $redirectId = $note->redirectId;
        } else {
            $note = new Note();
            $note->redirectId = (int)$redirectId;
        }

        return $this->asCpModal()
            ->action('not-found-redirects/notes/save')
            ->submitButtonLabel($note->id ? 'Save Note' : 'Add Note')
            ->contentTemplate('not-found-redirects/notes/_form', [
                'note' => $note,
                'redirectId' => $redirectId,
            ]);
    }

    // ── Mutation Actions ──────────────────────────────────────────────

    public function actionSave(): ?Response
    {
        $this->requirePermission('not-found-redirects:manageRedirects');
        $this->requirePostRequest();

        $noteService = NotFoundRedirects::getInstance()->getNoteService();

        $noteId = (int)$this->request->getBodyParam('noteId') ?: null;

        if ($noteId) {
            $query = NoteQuery::find();
            $query->andWhere(['id' => $noteId]);
            $note = $query->one();
            if (!$note) {
                throw new NotFoundHttpException('Note not found.');
            }
        } else {
            $note = new Note();
            $note->redirectId = (int)$this->request->getRequiredBodyParam('redirectId');
        }

        $note->note = $this->request->getBodyParam('note');

        if (!$noteService->saveNote($note)) {
            return $this->asModelFailure(
                $note,
                'Could not save note.',
                'note',
            );
        }

        return $this->asModelSuccess(
            $note,
            'Note saved.',
            'note',
        );
    }

    public function actionRenderList(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('not-found-redirects:manageRedirects');

        $redirectId = (int)$this->request->getRequiredParam('redirectId');

        $query = NoteQuery::find();
        $query->redirectId = $redirectId;
        $query->orderBy(['dateCreated' => SORT_ASC]);
        $notes = $query->all();

        $html = Craft::$app->getView()->renderTemplate('not-found-redirects/notes/_list', [
            'notes' => $notes,
        ]);

        return $this->asSuccess(data: ['html' => $html]);
    }

    public function actionDelete(): ?Response
    {
        $this->requirePermission('not-found-redirects:manageRedirects');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)$this->request->getRequiredBodyParam('id');
        NotFoundRedirects::getInstance()->getNoteService()->deleteNoteById($id);

        return $this->asSuccess();
    }
}
