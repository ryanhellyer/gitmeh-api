<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GitmehHourlyLimiter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'api:flush-hourly-hits',
    description: "Append the previous hour's total request count to the hourly totals log and remove the Redis key.",
)]
final class FlushHourlyHitsCommand extends Command
{
    public function __construct(
        private readonly GitmehHourlyLimiter $limiter,
        #[Autowire('%kernel.logs_dir%')]
        private readonly string $logDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $total = $this->limiter->flushCompletedHours();
        $hour = $this->limiter->lastHourString();

        $logPath = $this->logDir . '/gitmeh-hourly-totals.log';
        $line = $hour . "\t" . $total . ' requests' . PHP_EOL;

        if (file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX) === false) {
            $output->error('Failed to write hourly totals log: ' . $logPath);

            return Command::FAILURE;
        }

        $output->info("{$hour}: {$total} total requests logged to {$logPath}");

        return Command::SUCCESS;
    }
}
