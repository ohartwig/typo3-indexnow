<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Queue;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Koh\IndexNow\Typo3\Support\Row;

/**
 * Pages waiting to be announced, one row per page and language.
 *
 * Enqueueing is the only part that runs inside an editor's save, so it does
 * one SELECT and one bulk INSERT per 500 pages and nothing else: no URL
 * generation, no HTTP, no site lookups. Everything expensive happens later in
 * the worker.
 */
class PageQueue
{
    public const TABLE = 'tx_kohindexnow_queue';

    private const CHUNK = 500;

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * @param iterable<PageRef> $refs
     *
     * @return int number of rows added
     */
    public function enqueue(iterable $refs, Reason $reason, ?int $now = null): int
    {
        $now ??= time();
        $unique = [];
        foreach ($refs as $ref) {
            if ($ref->page > 0) {
                $unique[$ref->key()] = $ref;
            }
        }

        $added = 0;
        foreach (array_chunk($unique, self::CHUNK, true) as $chunk) {
            $existing = $this->existing(array_values($chunk));
            $missing = array_diff_key($chunk, $existing);
            $added += $this->insert(array_values($missing), $reason, $now);

            // A change outranks a queued go-live entry: the full submission's
            // strict rules would drop a page that was just hidden, and hiding
            // is precisely what has to be announced.
            if (!$reason->isStrict()) {
                $queuedForGoLive = array_filter($existing, static fn(string $queued): bool => $queued === Reason::Full->value);
                $this->upgradeToChange(array_values(array_intersect_key($chunk, $queuedForGoLive)), $reason);
            }
        }

        return $added;
    }

    /** @return list<QueueItem> */
    public function due(int $limit, ?int $now = null): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $result = $qb->select('uid', 'page', 'language', 'reason', 'attempts')
            ->from(self::TABLE)
            ->where($qb->expr()->lte('not_before', $qb->createNamedParameter($now ?? time(), Connection::PARAM_INT)))
            ->orderBy('uid')
            ->setMaxResults(max(1, $limit))
            ->executeQuery();

        $items = [];
        while ($row = $result->fetchAssociative()) {
            $items[] = new QueueItem(
                Row::int($row, 'uid'),
                new PageRef(Row::int($row, 'page'), Row::int($row, 'language')),
                Reason::tryFrom(Row::string($row, 'reason')) ?? Reason::Change,
                Row::int($row, 'attempts'),
            );
        }

        return $items;
    }

    /** @param list<int> $uids */
    public function remove(array $uids): void
    {
        foreach (array_chunk($uids, self::CHUNK) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $qb->delete(self::TABLE)
                ->where($qb->expr()->in('uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeStatement();
        }
    }

    public function defer(int $uid, int $attempts, int $notBefore): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['attempts' => $attempts, 'not_before' => $notBefore],
            ['uid' => $uid],
            [Connection::PARAM_INT, Connection::PARAM_INT],
        );
    }

    /** @return array{total: int, due: int, retrying: int} */
    public function statistics(?int $now = null): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->selectLiteral(
            'COUNT(*) AS total',
            sprintf('SUM(CASE WHEN %s <= %d THEN 1 ELSE 0 END) AS due', $qb->quoteIdentifier('not_before'), $now ?? time()),
            sprintf('SUM(CASE WHEN %s > 0 THEN 1 ELSE 0 END) AS retrying', $qb->quoteIdentifier('attempts')),
        )->from(self::TABLE)->executeQuery()->fetchAssociative() ?: [];

        return [
            'total' => Row::int($row, 'total'),
            'due' => Row::int($row, 'due'),
            'retrying' => Row::int($row, 'retrying'),
        ];
    }

    /**
     * @param list<PageRef> $refs
     *
     * @return array<string, string> "page:language" => queued reason
     */
    private function existing(array $refs): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $pages = array_values(array_unique(array_map(static fn(PageRef $ref): int => $ref->page, $refs)));
        $result = $qb->select('page', 'language', 'reason')
            ->from(self::TABLE)
            ->where($qb->expr()->in('page', $qb->createNamedParameter($pages, Connection::PARAM_INT_ARRAY)))
            ->executeQuery();

        $existing = [];
        while ($row = $result->fetchAssociative()) {
            $existing[Row::int($row, 'page') . ':' . Row::int($row, 'language')] = Row::string($row, 'reason');
        }

        return $existing;
    }

    /** @param list<PageRef> $refs */
    private function insert(array $refs, Reason $reason, int $now): int
    {
        if ($refs === []) {
            return 0;
        }
        $rows = array_map(static fn(PageRef $ref): array => [$ref->page, $ref->language, $reason->value, 0, 0, $now], $refs);
        $columns = ['page', 'language', 'reason', 'attempts', 'not_before', 'crdate'];
        $types = array_fill(0, 6, Connection::PARAM_INT);
        $types[2] = Connection::PARAM_STR;
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        try {
            return $connection->bulkInsert(self::TABLE, $rows, $columns, $types);
        } catch (UniqueConstraintViolationException) {
            // Another process queued one of these pages between our SELECT and
            // INSERT. Fall back to row by row and let the unique key decide.
            $added = 0;
            foreach ($rows as $row) {
                try {
                    $added += $connection->insert(self::TABLE, array_combine($columns, $row), $types);
                } catch (UniqueConstraintViolationException) {
                }
            }

            return $added;
        }
    }

    /** @param list<PageRef> $refs */
    private function upgradeToChange(array $refs, Reason $reason): void
    {
        foreach ($refs as $ref) {
            $this->connectionPool->getConnectionForTable(self::TABLE)->update(
                self::TABLE,
                ['reason' => $reason->value],
                ['page' => $ref->page, 'language' => $ref->language, 'reason' => Reason::Full->value],
            );
        }
    }
}
