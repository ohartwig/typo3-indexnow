<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Change;

use Koh\IndexNow\Typo3\Queue\PageQueue;
use Koh\IndexNow\Typo3\Queue\PageRef;
use Koh\IndexNow\Typo3\Queue\Reason;
use Psr\Log\LoggerInterface;

/**
 * Buffers what one DataHandler run changed and queues it in one go at the end.
 *
 * A bulk edit of two hundred records then costs one queue write, not two
 * hundred. Most records are only resolved to pages at flush time, after the
 * DataHandler is done; moves and deletions are resolved immediately (see
 * addPages()), because afterwards the record no longer tells where it was.
 *
 * Nothing in here may break a save: a failure is logged and swallowed, and
 * the page is simply announced on its next change.
 */
class ChangeCollector
{
    /** @var array<string, array{table: string, uid: int, allLanguages: bool}> */
    private array $records = [];

    /** @var array<string, PageRef> */
    private array $pages = [];

    public function __construct(
        private readonly RecordPageMapper $mapper,
        private readonly PageQueue $queue,
        private readonly LoggerInterface $logger,
    ) {}

    public function recordChanged(string $table, int $uid, bool $allLanguages = false): void
    {
        if ($uid <= 0 || !$this->mapper->isRelevantTable($table)) {
            return;
        }
        $key = $table . ':' . $uid;
        $this->records[$key] = [
            'table' => $table,
            'uid' => $uid,
            'allLanguages' => $allLanguages || ($this->records[$key]['allLanguages'] ?? false),
        ];
    }

    /** Resolve now, before the record changes place or disappears. */
    public function recordAboutToChange(string $table, int $uid): void
    {
        try {
            foreach ($this->mapper->pagesOf($table, $uid, true) as $ref) {
                $this->pages[$ref->key()] = $ref;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('IndexNow: could not resolve {table}:{uid} before it changed: {message}', [
                'table' => $table,
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function flush(): int
    {
        $records = $this->records;
        $pages = $this->pages;
        $this->records = [];
        $this->pages = [];
        if ($records === [] && $pages === []) {
            return 0;
        }

        try {
            foreach ($records as $record) {
                foreach ($this->mapper->pagesOf($record['table'], $record['uid'], $record['allLanguages']) as $ref) {
                    $pages[$ref->key()] = $ref;
                }
            }

            return $this->queue->enqueue($pages, Reason::Change);
        } catch (\Throwable $e) {
            $this->logger->error('IndexNow: {count} changed pages could not be queued: {message}', [
                'count' => count($pages),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return 0;
        }
    }
}
