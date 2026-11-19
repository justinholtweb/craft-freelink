<?php

namespace justinholtweb\freelink\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\freelink\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Migration tools (Hyper, Linkit, Typed Link Field, Craft Link → FreeLink) in the CP section.
 *
 * Admin only: a migration rewrites field types and content.
 */
class MigrationsController extends Controller
{
    public const SOURCES = ['hyper', 'linkit', 'typedlinkfield', 'craftlink'];

    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requireAdmin(false);

        $migrate = Plugin::getInstance()->migrate;
        $session = Craft::$app->getSession();

        $history = $migrate->getStatus();
        $fieldNames = [];
        foreach ($history as $row) {
            $field = Craft::$app->getFields()->getFieldById((int)$row['fieldId']);
            $fieldNames[(int)$row['fieldId']] = $field ? $field->name . ' (' . $field->handle . ')' : null;
        }

        return $this->renderTemplate('freelink/_cp/migrations', [
            'available' => $migrate->getAvailableMigrations(),
            'history' => $history,
            'fieldNames' => $fieldNames,
            'lastRun' => $session->getFlash('freelink-migration-run', null, true),
            'allowAdminChanges' => Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    /**
     * Runs one migration. A dry run is the default; a real run needs an elevated session.
     */
    public function actionRun(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireAdmin(true);

        $source = (string)$this->request->getRequiredBodyParam('source');
        if (!in_array($source, self::SOURCES, true)) {
            throw new BadRequestHttpException('Unknown migration source.');
        }

        $field = trim((string)$this->request->getBodyParam('field', ''));
        if ($field !== '' && !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $field)) {
            throw new BadRequestHttpException('Invalid field handle.');
        }

        $dryRun = (bool)$this->request->getBodyParam('dryRun', true);
        $backup = (bool)$this->request->getBodyParam('backup', false);

        if (!$dryRun) {
            $this->requireElevatedSession();
        }

        $result = Plugin::getInstance()->migrate->runMigration($source, $field !== '' ? $field : null, $dryRun, $backup);

        Craft::$app->getSession()->setFlash('freelink-migration-run', [
            'source' => $source,
            'field' => $field,
            'dryRun' => $dryRun,
            'success' => (bool)$result['success'],
            'log' => array_slice(array_map('strval', $result['log']), 0, 500),
        ]);

        if ($result['success']) {
            $this->setSuccessFlash($dryRun
                ? Craft::t('freelink', 'Dry run complete. Nothing was changed.')
                : Craft::t('freelink', 'Migration complete.'));
        } else {
            $this->setFailFlash(Craft::t('freelink', 'Migration failed. See the log.'));
        }

        return $this->redirect('freelink/migrations');
    }

    /**
     * Before 5.3 the migration page was template-routed at `freelink/migrate`.
     */
    public function actionLegacyRedirect(): Response
    {
        return $this->redirect('freelink/migrations', 301);
    }
}
