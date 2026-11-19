<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Http\Controllers;

use CraftCms\Cms\Auth\Concerns\ConfirmsPasswords;
use CraftCms\Cms\Cms;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Facades\Fields;
use Illuminate\Http\Request;
use JustinHoltWeb\FreeLink\Services\Migrate;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * Migration tools in the CP section. Admin only (route middleware): a migration rewrites field
 * types and content.
 */
class MigrationsController
{
    use ConfirmsPasswords;
    use RespondsWithFlash;

    public const array SOURCES = ['hyper', 'linkit', 'typedlinkfield', 'craftlink'];

    public function index(Request $request, Migrate $migrate): CpScreenResponse
    {
        $history = $migrate->getStatus();
        $fieldNames = [];
        foreach ($history as $row) {
            $field = Fields::getFieldById((int) $row['fieldId']);
            $fieldNames[(int) $row['fieldId']] = $field ? $field->name.' ('.$field->handle.')' : null;
        }

        return new CpScreenResponse()
            ->title(t('Migrations', category: 'freelink'))
            ->selectedSubnavItem('migrations')
            ->contentTemplate('freelink/migrations', [
                'available' => $migrate->getAvailableMigrations(),
                'craft5Only' => Migrate::CRAFT5_ONLY,
                'labels' => Migrate::LABELS,
                'history' => $history,
                'fieldNames' => $fieldNames,
                'lastRun' => $request->session()->get('freelink-migration-run'),
                'allowAdminChanges' => Cms::config()->allowAdminChanges,
            ]);
    }

    /**
     * Runs one migration. A dry run is the default; a real run needs a recently confirmed password.
     */
    public function run(Request $request, Migrate $migrate): Response
    {
        $source = (string) $request->input('source');
        abort_unless(in_array($source, self::SOURCES, true), 400, 'Unknown migration source.');

        $field = trim((string) $request->input('field', ''));
        abort_if($field !== '' && ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $field), 400, 'Invalid field handle.');

        $dryRun = $request->boolean('dryRun', true);
        $backup = $request->boolean('backup');

        if (! $dryRun) {
            $this->requireConfirmedPassword();
        }

        $result = $migrate->runMigration($source, $field !== '' ? $field : null, $dryRun, $backup);

        $request->session()->flash('freelink-migration-run', [
            'source' => $source,
            'field' => $field,
            'dryRun' => $dryRun,
            'success' => $result['success'],
            'log' => array_slice($result['log'], 0, 500),
        ]);

        if ($result['success']) {
            return $this->asSuccess($dryRun
                ? t('Dry run complete. Nothing was changed.', category: 'freelink')
                : t('Migration complete.', category: 'freelink'), redirect: cp_url('freelink/migrations'));
        }

        return $this->asFailure(t('Migration failed. See the log.', category: 'freelink'));
    }
}
