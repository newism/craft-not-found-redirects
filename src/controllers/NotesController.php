<?php

namespace newism\notfoundredirects\controllers;

use Craft;
use craft\web\Controller;
use newism\notfoundredirects\models\Note;
use newism\notfoundredirects\NotFoundRedirects;
use yii\web\Response;

class NotesController extends Controller
{
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function beforeAction($action): bool
    {
        $this->requirePermission('not-found-redirects:manageRedirects');

        return parent::beforeAction($action);
    }

    // ── CP Screen Actions (for CpScreenSlideout) ──────────────────────

    public function actionEdit(?int $noteId = null, ?int $redirectId = null): Response
    {
        // Support both route params and query params (slideouts use action URLs)
        $noteId = $noteId ?? $this->request->getParam('noteId');
        $redirectId = $redirectId ?? $this->request->getParam('redirectId');

        if ($noteId) {
            $note = NotFoundRedirects::getInstance()->notes->findById((int) $noteId);
            if (!$note) {
                throw new \yii\web\NotFoundHttpException('Note not found.');
            }
            $redirectId = $note->redirectId;
        } else {
            $note = new Note();
            $note->redirectId = (int) $redirectId;
        }

        return $this->asCpModal()
            ->action('not-found-redirects/notes/save')
            ->submitButtonLabel($note->id ? 'Save Note' : 'Add Note')
            ->contentTemplate('not-found-redirects/_note-form', [
                'note' => $note,
                'redirectId' => $redirectId,
            ]);
    }

    // ── Mutation Actions ──────────────────────────────────────────────

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $noteService = NotFoundRedirects::getInstance()->notes;

        $noteId = (int) $this->request->getBodyParam('noteId') ?: null;
        $redirectId = (int) $this->request->getBodyParam('redirectId');

        if ($noteId) {
            $note = $noteService->findById($noteId);
            if (!$note) {
                throw new \yii\web\NotFoundHttpException('Note not found.');
            }
        } else {
            $note = new Note();
            $note->redirectId = $redirectId;
        }

        $note->note = $this->request->getBodyParam('note');

        if (!$noteService->save($note)) {
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

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int) $this->request->getRequiredBodyParam('id');
        NotFoundRedirects::getInstance()->notes->deleteById($id);

        return $this->asSuccess();
    }
}
