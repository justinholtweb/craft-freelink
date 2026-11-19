<?php

namespace justinholtweb\freelink\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\freelink\Plugin;
use yii\queue\RetryableJobInterface;

/**
 * Checks the URL links in the link index that are due (or all of them with `force`).
 *
 * Takes no URL: it only ever reads what the index holds.
 */
class CheckLinks extends BaseJob implements RetryableJobInterface
{
    public bool $force = false;

    public function execute($queue): void
    {
        Plugin::getInstance()->checker->run($this->force, null, function(int $done, int $total) use ($queue) {
            $this->setProgress($queue, $total ? $done / $total : 1, Craft::t('freelink', '{done} of {total} URLs', [
                'done' => $done,
                'total' => $total,
            ]));
        });
    }

    public function getTtr(): int
    {
        // 500 URLs at up to ~2 × 5 s each plus host delays.
        return 3600;
    }

    public function canRetry($attempt, $error): bool
    {
        return false;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('freelink', 'Checking FreeLink URLs');
    }
}
