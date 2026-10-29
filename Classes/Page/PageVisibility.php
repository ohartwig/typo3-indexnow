<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Page;

use Koh\IndexNow\Typo3\Queue\PageRef;
use Koh\IndexNow\Typo3\Support\Row;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Whether a page in a language is public: live, not deleted, not hidden, inside
 * its start and end time — the page, its translation and every ancestor that
 * extends its settings to subpages.
 *
 * This is what decides whether a change is announced at all. A change of a
 * page that is not public is only worth announcing when the page was public
 * before it, because then the engine has to learn that it is gone. A page
 * that was never public — created hidden, or published hidden from a
 * workspace — has no URL anyone may know yet, so it is not announced until it
 * becomes public.
 *
 * Reads the database afresh on every call, because it is asked about the same
 * page before and after a save in one request.
 */
class PageVisibility
{
    private readonly PageData $pages;

    public function __construct(ConnectionPool $connectionPool)
    {
        // Our own instance: the shared one memoizes rows for the worker.
        $this->pages = new PageData($connectionPool);
    }

    /**
     * @param iterable<PageRef> $refs
     *
     * @return array<string, bool> page:language => public at $now
     */
    public function of(iterable $refs, ?int $now = null): array
    {
        $now ??= time();
        $this->pages->reset();
        $public = [];
        foreach ($refs as $ref) {
            $public[$ref->key()] ??= $this->isPublic($ref, $now);
        }
        $this->pages->reset();

        return $public;
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $record the page itself, or its translation
     * @param list<array<string, mixed>> $rootline the page first, then its ancestors
     */
    public static function isVisible(array $page, array $record, array $rootline, int $now): bool
    {
        if (!self::rowIsVisible($page, $now) || !self::rowIsVisible($record, $now)) {
            return false;
        }
        foreach (array_slice($rootline, 1) as $ancestor) {
            if (Row::bool($ancestor, 'extendToSubpages') && !self::rowIsVisible($ancestor, $now)) {
                return false;
            }
        }

        return true;
    }

    private function isPublic(PageRef $ref, int $now): bool
    {
        $page = $this->pages->page($ref->page);
        if ($page === null || Row::int($page, 'sys_language_uid') !== 0) {
            return false;
        }
        $record = $ref->language === 0 ? $page : $this->pages->translation($ref->page, $ref->language);
        if ($record === null) {
            return false;
        }

        return self::isVisible($page, $record, $this->pages->rootline($ref->page), $now);
    }

    /** @param array<string, mixed> $row */
    private static function rowIsVisible(array $row, int $now): bool
    {
        $start = Row::int($row, 'starttime');
        $end = Row::int($row, 'endtime');

        return !Row::bool($row, 'deleted')
            && !Row::bool($row, 'hidden')
            && ($start === 0 || $start <= $now)
            && ($end === 0 || $end > $now);
    }
}
