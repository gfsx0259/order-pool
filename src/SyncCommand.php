<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool;

use Cycle\Database\DatabaseProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncCommand extends Command
{
    public function __construct(
        protected readonly DatabaseProviderInterface $databaseProvider,
        protected readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $users = $this->databaseProvider->database()->query(
            "SELECT * FROM users",
        )->fetchAll();

        $output->writeln(json_encode($users, JSON_THROW_ON_ERROR));
        $output->writeln("🚀 SyncCommand upd.\n");

        return Command::SUCCESS;
    }
}