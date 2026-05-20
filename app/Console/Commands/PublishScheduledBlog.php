<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Blog\BlogService;
use Illuminate\Console\Command;

/**
 * Cron flip — promote scheduled blog posts whose `scheduled_for` has
 * already passed into the published state.
 *
 * Wired into the scheduler at every 5 minutes (see App\Console\Kernel).
 * Safe to invoke ad-hoc from the CLI for debugging.
 */
class PublishScheduledBlog extends Command
{
    protected $signature = 'flik:blog:publish-scheduled';

    protected $description = 'Promote any scheduled blog posts whose scheduled_for is now in the past.';

    public function handle(BlogService $service): int
    {
        $count = $service->publishScheduled();

        $this->info(sprintf('Published %d scheduled blog post(s).', $count));

        return self::SUCCESS;
    }
}
