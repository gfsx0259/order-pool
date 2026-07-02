<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("🚀 SyncCommand upd.\n");

        return Command::SUCCESS;
    }
}