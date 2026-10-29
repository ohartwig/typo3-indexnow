<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Change;

use Koh\IndexNow\Typo3\Page\PageVisibility;
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
 * Only pages that are public after the change, or were public before it, are
 * queued. Hiding, deleting or expiring a public page is announced, so the
 * engine learns it is gone; a page that was never public — created hidden,
 * edited while hidden, published hidden from a workspace — is not, because
 * its URL would reach the engines before the page does. Visibility before the
 * change is read in the DataHandler's pre-processing hooks (recordBeforeChange,
 * recordAboutToChange).
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

    /** @var array<string, bool> page:language => public before this run first touched it */
    private array $wasPublic = [];

    public function __construct(
        private readonly RecordPageMapper $mapper,
        private readonly PageQueue $queue,
        private readonly PageVisibility $visibility,
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

    /**
     * Remember whether a page record's languages are public, before it is saved
     * or published. Only a page record can change a page's visibility.
     */
    public function recordBeforeChange(string $table, int $uid): void
    {
        if ($table !== 'pages' || $uid <= 0) {
            return;
        }
        try {
            $this->rememberVisibility($this->mapper->pagesOf($table, $uid, true));
        } catch (\Throwable $e) {
            $this->logger->warning('IndexNow: could not read {table}:{uid} before it changed: {message}', [
                'table' => $table,
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** Resolve now, before the record changes place or disappears. */
    public function recordAboutToChange(string $table, int $uid): void
    {
        try {
            $refs = $this->mapper->pagesOf($table, $uid, true);
            $this->rememberVisibility($refs);
            foreach ($refs as $ref) {
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
        $wasPublic = $this->wasPublic;
        $this->records = [];
        $this->pages = [];
        $this->wasPublic = [];
        if ($records === [] && $pages === []) {
            return 0;
        }

        try {
            foreach ($records as $record) {
                foreach ($this->mapper->pagesOf($record['table'], $record['uid'], $record['allLanguages']) as $ref) {
                    $pages[$ref->key()] = $ref;
                }
            }

            $isPublic = $this->visibility->of($pages);
            $announced = array_filter(
                $pages,
                static fn(PageRef $ref): bool => ($wasPublic[$ref->key()] ?? false) || ($isPublic[$ref->key()] ?? false),
            );

            return $this->queue->enqueue($announced, Reason::Change);
        } catch (\Throwable $e) {
            $this->logger->error('IndexNow: {count} changed pages could not be queued: {message}', [
                'count' => count($pages),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return 0;
        }
    }

    /**
     * The first reading in a run wins: it is the state before the run.
     *
     * @param list<PageRef> $refs
     */
    private function rememberVisibility(array $refs): void
    {
        $unknown = array_filter($refs, fn(PageRef $ref): bool => !array_key_exists($ref->key(), $this->wasPublic));
        $this->wasPublic += $this->visibility->of($unknown);
    }
}
