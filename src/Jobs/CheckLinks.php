<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Jobs;

use CraftCms\Cms\Queue\Job;
use JustinHoltWeb\FreeLink\Services\Checker;
use Override;

use function CraftCms\Cms\t;

/**
 * Checks the URL links in the link index that are due (or all of them with `force`).
 *
 * Takes no URL: it only ever reads what the index holds.
 */
class CheckLinks extends Job
{
    /** 500 URLs at up to ~2 × 5 s each, plus host delays. */
    public int $timeout = 3600;

    /** Never retried: a failed run is picked up by the next one, and retrying would re-fetch. */
    public int $tries = 1;

    public function __construct(public bool $force = false)
    {
        parent::__construct();
    }

    public function handle(Checker $checker): void
    {
        $checker->run($this->force, null, function (int $done, int $total): void {
            $this->setProgress($total ? (int) floor($done / $total * 100) : 100, t('{done} of {total} URLs', [
                'done' => $done,
                'total' => $total,
            ], category: 'freelink'));
        });
    }

    #[Override]
    protected function defaultDescription(): string
    {
        return t('Checking FreeLink URLs', category: 'freelink');
    }
}
