<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Change;

use Koh\IndexNow\Typo3\Event\ResolveRecordPagesEvent;
use Koh\IndexNow\Typo3\Page\PageData;
use Koh\IndexNow\Typo3\Queue\PageRef;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Koh\IndexNow\Typo3\Support\Row;
use Koh\IndexNow\Typo3\Support\Tca;

/**
 * From "record X of table Y changed" to "these pages in these languages changed".
 *
 * Page records map to themselves. Every other record maps to the page it is
 * stored on, in its own language, or in all languages of that page when it
 * is marked "all languages" (-1). Tables that never render on a page are
 * skipped by name and by TCA: backend and frontend users, system tables,
 * root-level and admin-only tables.
 */
class RecordPageMapper
{
    /** sys_* tables are ignored, except this one: an image on a page is page content. */
    private const SYSTEM_TABLE_ALLOWED = ['sys_file_reference'];

    private const IGNORED_PREFIXES = ['be_', 'fe_', 'sys_', 'tx_scheduler_', 'tx_kohindexnow_', 'cache_', 'index_'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PageData $pages,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function isRelevantTable(string $table): bool
    {
        $ctrl = Tca::ctrl($table);
        if ($ctrl === null) {
            return false;
        }
        if ($table === 'pages' || in_array($table, self::SYSTEM_TABLE_ALLOWED, true)) {
            return true;
        }
        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return false;
            }
        }

        return Row::int($ctrl, 'rootLevel') !== 1 && !Row::bool($ctrl, 'adminOnly');
    }

    /**
     * @param bool $allLanguages also the translations — for changes that affect them all: visibility, deletion, moves
     *
     * @return list<PageRef>
     */
    public function pagesOf(string $table, int $uid, bool $allLanguages = false): array
    {
        if (!$this->isRelevantTable($table)) {
            return [];
        }
        $record = $this->record($table, $uid);

        return $record === null ? [] : $this->pagesOfRecord($table, $record, $allLanguages);
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return list<PageRef>
     */
    public function pagesOfRecord(string $table, array $record, bool $allLanguages = false): array
    {
        if ($table === 'pages') {
            $pages = $this->pagesOfPageRecord($record, $allLanguages);
        } else {
            $page = Row::int($record, 'pid');
            $languageField = Tca::ctrlString($table, 'languageField');
            $language = $languageField !== '' ? Row::int($record, $languageField) : 0;
            $pages = $page <= 0 ? [] : ($language === -1
                ? $this->allLanguagesOf($page)
                : [new PageRef($page, max(0, $language))]);
        }

        $event = new ResolveRecordPagesEvent($table, $record, $pages);
        $this->eventDispatcher->dispatch($event);

        return $event->getPages();
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return list<PageRef>
     */
    private function pagesOfPageRecord(array $record, bool $allLanguages): array
    {
        $language = Row::int($record, 'sys_language_uid');
        if ($language > 0) {
            $parent = Row::int($record, 'l10n_parent');

            return $parent > 0 ? [new PageRef($parent, $language)] : [];
        }

        $page = Row::int($record, 'uid');

        return $allLanguages ? $this->allLanguagesOf($page) : [new PageRef($page, 0)];
    }

    /** @return list<PageRef> */
    private function allLanguagesOf(int $page): array
    {
        $refs = [new PageRef($page, 0)];
        foreach ($this->pages->translationLanguages($page) as $language) {
            $refs[] = new PageRef($page, $language);
        }

        return $refs;
    }

    /** @return array<string, mixed>|null */
    private function record(string $table, int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')->from($table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchAssociative();

        return $row === false ? null : $row;
    }
}
