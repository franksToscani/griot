<?php

namespace App\Console\Commands;

use App\Support\FailedJobsReport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled every hour in routes/console.php (issue #63, docs/deploy.md). The work is
 * FailedJobsReport's: a Log::error with the number of new failed jobs and their classes, never a
 * personal datum.
 */
#[Signature('jobs:report-failed')]
#[Description('Write in the log how many jobs failed since the last check, without personal data')]
class ReportFailedJobsCommand extends Command
{
    /**
     * Execute the console command. It succeeds with or without new failed jobs: the warning is
     * the line in the log.
     */
    public function handle(FailedJobsReport $report): int
    {
        $count = $report->run();

        $this->info(__('console.failed_jobs.summary', ['count' => $count]));

        return self::SUCCESS;
    }
}
