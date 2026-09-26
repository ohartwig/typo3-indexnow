<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Command;

use Koh\IndexNow\Typo3\Activation\ActivationGate;
use Koh\IndexNow\Typo3\Queue\PageQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Attribute\AsNonSchedulableCommand;
use TYPO3\CMS\Core\Site\SiteFinder;

/** Answers "why is nothing announced?" per host, and shows the queue. */
#[AsCommand('indexnow:status', 'IndexNow: show per host whether it announces, why not, and the key file URL.')]
#[AsNonSchedulableCommand]
final class StatusCommand extends Command
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly ActivationGate $gate,
        private readonly PageQueue $queue,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            foreach (array_keys($this->gate->hostsOf($site)) as $host) {
                $activation = $this->gate->forHost($site, $host);
                $rows[] = [
                    $site->getIdentifier(),
                    $host,
                    $activation->active ? '<info>active</info>' : '<comment>inactive</comment>',
                    $activation->active ? (string) $activation->keyFileUrl($host) : $activation->reason,
                ];
            }
        }
        $io->table(['Site', 'Host', 'State', 'Key file / reason'], $rows);

        $statistics = $this->queue->statistics();
        $io->writeln(sprintf('Queue: %d pages, %d due, %d waiting after a failed attempt.', $statistics['total'], $statistics['due'], $statistics['retrying']));

        return Command::SUCCESS;
    }
}
