<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GitmehDailyApiLimiter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'api:flush-daily-hits',
    description: "Persist yesterday's Redis gitmeh hit hash and remove that Redis key.",
)]
final class FlushDailyApiHitsCommand extends Command
{
    public function __construct(
        private readonly GitmehDailyApiLimiter $limiter,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->limiter->flushCompletedDays();
        $output->info('Redis day keys processed (deleted after persist): ' . $deleted);

        return Command::SUCCESS;
    }
}
