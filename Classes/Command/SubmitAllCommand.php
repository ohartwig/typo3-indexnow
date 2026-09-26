<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Command;

use Koh\IndexNow\Typo3\Processing\FullSubmission;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Attribute\AsNonSchedulableCommand;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Queue every indexable page of one or more sites again.
 *
 * The first announcement happens by itself; this is for afterwards — a
 * relaunch, a domain move, a search engine that lost track. Pages are only
 * queued; the next indexnow:run sends them, and only for active hosts.
 */
#[AsCommand('indexnow:submit-all', 'IndexNow: queue all indexable pages of the given sites (default: all) for announcement.')]
#[AsNonSchedulableCommand]
final class SubmitAllCommand extends Command
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly FullSubmission $fullSubmission,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Site identifiers; all sites when omitted.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $identifiers */
        $identifiers = (array) $input->getArgument('site');

        try {
            $sites = $identifiers === []
                ? array_values($this->siteFinder->getAllSites())
                : array_map(fn(string $identifier) => $this->siteFinder->getSiteByIdentifier($identifier), $identifiers);
        } catch (SiteNotFoundException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        foreach ($sites as $site) {
            $io->writeln(sprintf('%s: %d pages queued', $site->getIdentifier(), $this->fullSubmission->queueSite($site)));
        }
        $io->note('Queued only. The next indexnow:run sends them for hosts that announce (see indexnow:status).');

        return Command::SUCCESS;
    }
}
