<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Setup;

use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Package\Event\PackageInitializationEvent;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Creates the "indexnow:run every five minutes" scheduler task on extension:setup.
 *
 * A feature that needs a manual backend click after every installation is a
 * feature that silently does not run on the next one. extension:setup is
 * part of every deployment, so the task exists wherever the extension does.
 *
 * Idempotent, and hands-off afterwards: an existing task — including one an
 * administrator disabled or re-timed — is never touched; only a missing one
 * is created. Written directly rather than through the DataHandler because
 * extension:setup has no backend user.
 */
#[AsEventListener(identifier: 'koh-indexnow/provision-scheduler-task')]
final readonly class ProvisionSchedulerTask
{
    public const COMMAND = 'indexnow:run';
    public const INTERVAL = 300;

    private const TABLE = 'tx_scheduler_task';

    public function __construct(
        private ConnectionPool $connectionPool,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(PackageInitializationEvent $event): void
    {
        if ($event->getExtensionKey() !== 'koh_indexnow' || !ExtensionManagementUtility::isLoaded('scheduler')) {
            return;
        }

        try {
            if ($this->taskExists()) {
                return;
            }
            $this->createTask(time());
        } catch (\Throwable $e) {
            // Never fail a deployment over this; the task can be added by hand.
            $this->logger->warning('IndexNow: could not create the scheduler task: {message}', ['message' => $e->getMessage()]);
        }
    }

    private function taskExists(): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();

        $count = $qb->count('uid')->from(self::TABLE)
            ->where(
                $qb->expr()->eq('tasktype', $qb->createNamedParameter(self::COMMAND)),
                $qb->expr()->eq('deleted', 0),
            )
            ->executeQuery()->fetchOne();

        return is_numeric($count) && (int) $count > 0;
    }

    private function createTask(int $now): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => 0,
            'crdate' => $now,
            'tasktype' => self::COMMAND,
            'task_group' => 0,
            'description' => 'IndexNow: announce changed, scheduled and newly launched pages. Created by EXT:koh_indexnow.',
            'nextexecution' => $now,
            'disable' => 0,
            'deleted' => 0,
            // Arrays, typed as JSON: the connection encodes JSON columns
            // itself, and a pre-encoded string ends up encoded twice — the
            // scheduler then reads a string where it expects its settings.
            'parameters' => [
                'commandIdentifier' => self::COMMAND,
                'arguments' => [],
                'options' => [],
                'optionValues' => [],
            ],
            'execution_details' => [
                'start' => $now,
                'end' => 0,
                'interval' => self::INTERVAL,
                'multiple' => false,
                'cronCmd' => '',
                'isNewSingleExecution' => false,
            ],
        ], [
            'parameters' => Types::JSON,
            'execution_details' => Types::JSON,
        ]);
    }
}
