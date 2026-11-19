<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\FreeLink\Services\Checker;

/**
 * Checks stored URL links over HTTP (only URLs saved in FreeLink fields; never anything else).
 */
class LinksCheckCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'freelink:links:check {--force : Check every URL, not only the ones due} {--limit= : The most URLs to check (default: the maxChecksPerRun setting)}';

    #[\Override]
    protected $description = 'Checks the URL links stored in FreeLink fields over HTTP.';

    #[\Override]
    protected $aliases = ['freelink/links/check'];

    public function handle(Checker $checker): int
    {
        $limit = $this->option('limit');

        $result = $checker->run((bool) $this->option('force'), is_numeric($limit) ? max(1, (int) $limit) : null, function (int $done, int $total, string $url): void {
            $this->line(sprintf('  [%d/%d] %s', $done, $total, $url));
        });

        if ($result['skipped']) {
            $this->warn('External link checks are turned off in the FreeLink settings.');

            return self::SUCCESS;
        }

        $summary = sprintf('Checked %d URL(s).', $result['checked']);
        foreach ($result['results'] as $status => $n) {
            $summary .= " $status: $n";
        }

        $this->info($summary);

        return self::SUCCESS;
    }
}
