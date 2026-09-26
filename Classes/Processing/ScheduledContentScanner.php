<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Processing;

use Koh\IndexNow\Typo3\Change\RecordPageMapper;
use Koh\IndexNow\Typo3\Queue\PageQueue;
use Koh\IndexNow\Typo3\Queue\PageRef;
use Koh\IndexNow\Typo3\Queue\Reason;
use Koh\IndexNow\Typo3\Support\Tca;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;

/**
 * Finds content that went live or expired without anyone saving it.
 *
 * A post scheduled for Tuesday 07:00 changes nothing in the database at
 * 07:00; its starttime simply passes. Every table with a start or end time is
 * asked, per run, which records crossed one since the previous run.
 *
 * The window is persisted, so a run that was skipped (a deploy, a paused
 * scheduler) is caught up by the next one, capped at MAX_CATCH_UP so a
 * scheduler that was off for a month does not re-announce the whole archive.
 * On the very first run there is no window yet; the full submission covers
 * that moment.
 */
class ScheduledContentScanner
{
    private const REGISTRY_NAMESPACE = 'koh_indexnow';
    private const REGISTRY_KEY = 'scheduledScanUntil';
    private const MAX_CATCH_UP = 7 * 86400;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly RecordPageMapper $mapper,
        private readonly PageQueue $queue,
        private readonly Registry $registry,
    ) {}

    /** @return int pages queued */
    public function scan(?int $now = null): int
    {
        $now ??= time();
        $previous = $this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY);
        if (!is_int($previous)) {
            $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, $now);

            return 0;
        }
        $since = max($previous, $now - self::MAX_CATCH_UP);
        if ($since >= $now) {
            return 0;
        }

        $refs = [];
        foreach ($this->scheduledTables() as $table => $fields) {
            foreach ($this->crossed($table, $fields, $since, $now) as $record) {
                foreach ($this->mapper->pagesOfRecord($table, $record, $table === 'pages') as $ref) {
                    $refs[$ref->key()] = $ref;
                }
            }
        }

        $queued = $this->queue->enqueue($refs, Reason::Schedule, $now);
        // Only now: a run that fails before this line scans the same window again.
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, $now);

        return $queued;
    }

    /** @return array<string, list<string>> table => start/end columns */
    private function scheduledTables(): array
    {
        $tables = [];
        foreach (Tca::tables() as $table) {
            if (!$this->mapper->isRelevantTable($table)) {
                continue;
            }
            $fields = array_values(array_filter(
                [Tca::enableColumn($table, 'starttime'), Tca::enableColumn($table, 'endtime')],
                static fn(string $field): bool => $field !== '',
            ));
            if ($fields !== []) {
                $tables[$table] = $fields;
            }
        }

        return $tables;
    }

    /**
     * @param list<string> $fields
     *
     * @return iterable<array<string, mixed>>
     */
    private function crossed(string $table, array $fields, int $since, int $until): iterable
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();

        $windows = array_map(
            static fn(string $field): string => (string) $qb->expr()->and(
                $qb->expr()->gt($field, $qb->createNamedParameter($since, Connection::PARAM_INT)),
                $qb->expr()->lte($field, $qb->createNamedParameter($until, Connection::PARAM_INT)),
            ),
            $fields,
        );
        $qb->select('*')->from($table)->where($qb->expr()->or(...$windows));
        $deleteField = Tca::ctrlString($table, 'delete');
        if ($deleteField !== '') {
            $qb->andWhere($qb->expr()->eq($deleteField, 0));
        }
        if (Tca::ctrlFlag($table, 'versioningWS')) {
            $qb->andWhere($qb->expr()->eq('t3ver_wsid', 0));
        }

        $result = $qb->executeQuery();
        while ($row = $result->fetchAssociative()) {
            yield $row;
        }
    }
}
