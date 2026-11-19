<?php

namespace justinholtweb\freelink\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\freelink\Plugin;
use yii\console\ExitCode;

/**
 * Link index and link checks.
 */
class LinksController extends Controller
{
    /**
     * @var bool Check every URL, not only the ones due.
     */
    public bool $force = false;

    /**
     * @var int|null The most URLs to check (defaults to the maxChecksPerRun setting).
     */
    public ?int $limit = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'check') {
            $options[] = 'force';
            $options[] = 'limit';
        }

        return $options;
    }

    /**
     * Rebuilds the link index from field values.
     *
     * php craft freelink/links/reindex
     */
    public function actionReindex(): int
    {
        $this->stdout("Indexing FreeLink links...\n");

        $count = Plugin::getInstance()->linkIndex->rebuild(function(int $done, int $total) {
            Console::updateProgress($done, $total);
        });

        Console::endProgress();
        $this->stdout("Indexed $count element(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Checks stored URL links over HTTP (only URLs saved in FreeLink fields; never anything else).
     *
     * php craft freelink/links/check [--force] [--limit=100]
     */
    public function actionCheck(): int
    {
        $result = Plugin::getInstance()->checker->run($this->force, $this->limit, function(int $done, int $total, string $url) {
            $this->stdout(sprintf("  [%d/%d] %s\n", $done, $total, $url));
        });

        if ($result['skipped']) {
            $this->stderr("External link checks are turned off in the FreeLink settings.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout(sprintf("Checked %d URL(s).", $result['checked']));
        foreach ($result['results'] as $status => $n) {
            $this->stdout(" $status: $n");
        }
        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Prints link counts and problems.
     *
     * php craft freelink/links/status
     */
    public function actionStatus(): int
    {
        $index = Plugin::getInstance()->linkIndex;

        foreach (['all', 'problems', 'ok', 'unchecked'] as $status) {
            $count = (int)$index->createQuery(['status' => $status])->select(['i.id'])->count('*');
            $this->stdout(sprintf("%-10s %d\n", $status, $count));
        }

        return ExitCode::OK;
    }
}
