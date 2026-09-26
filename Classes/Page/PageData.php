<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Page;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use Koh\IndexNow\Typo3\Support\Row;
use Koh\IndexNow\Typo3\Support\Tca;

/**
 * Raw live-workspace page records, deliberately without enable-field restrictions.
 *
 * Announcing a removal needs the URL of a page that is already hidden or
 * deleted, and TYPO3's restricted repositories cannot see those. Every
 * visibility rule is therefore applied explicitly, in PageUrlResolver, where
 * it can be read and tested.
 *
 * Rows are memoized for one run; call reset() between runs of a long-lived
 * worker.
 */
class PageData
{
    private const MAX_ROOTLINE_DEPTH = 99;

    /** @var array<int, array<string, mixed>|null> */
    private array $pages = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $translations = [];

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /** @return array<string, mixed>|null the default-language record, including hidden and deleted ones */
    public function page(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }
        if (!array_key_exists($uid, $this->pages)) {
            $qb = $this->queryBuilder();
            $row = $qb->select('*')->from('pages')
                ->where(
                    $qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)),
                    ...$this->liveOnly($qb),
                )
                ->executeQuery()->fetchAssociative();
            $this->pages[$uid] = $row === false ? null : $row;
        }

        return $this->pages[$uid];
    }

    /**
     * The translation of a page, preferring a live one over a deleted one.
     *
     * @return array<string, mixed>|null
     */
    public function translation(int $pageUid, int $language): ?array
    {
        $key = $pageUid . ':' . $language;
        if (!array_key_exists($key, $this->translations)) {
            $qb = $this->queryBuilder();
            $row = $qb->select('*')->from('pages')
                ->where(
                    $qb->expr()->eq('l10n_parent', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                    $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($language, Connection::PARAM_INT)),
                    ...$this->liveOnly($qb),
                )
                ->orderBy('deleted')
                ->addOrderBy('uid', 'DESC')
                ->setMaxResults(1)
                ->executeQuery()->fetchAssociative();
            $this->translations[$key] = $row === false ? null : $row;
        }

        return $this->translations[$key];
    }

    /**
     * Languages a page has a translation record in, deleted ones included.
     *
     * @return list<int>
     */
    public function translationLanguages(int $pageUid): array
    {
        $qb = $this->queryBuilder();
        $languages = $qb->selectLiteral('DISTINCT ' . $qb->quoteIdentifier('sys_language_uid'))->from('pages')
            ->where(
                $qb->expr()->eq('l10n_parent', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->gt('sys_language_uid', 0),
                ...$this->liveOnly($qb),
            )
            ->executeQuery()->fetchFirstColumn();

        return array_map(static fn(mixed $language): int => is_numeric($language) ? (int) $language : 0, $languages);
    }

    /**
     * The page and its ancestors, nearest first, stopping at the tree root.
     *
     * Walks deleted ancestors too; a deleted parent does not change which site
     * a page belonged to.
     *
     * @return list<array<string, mixed>>
     */
    public function rootline(int $uid): array
    {
        $rootline = [];
        $seen = [];
        while ($uid > 0 && count($rootline) <= self::MAX_ROOTLINE_DEPTH && !isset($seen[$uid])) {
            $seen[$uid] = true;
            $row = $this->page($uid);
            if ($row === null) {
                break;
            }
            $rootline[] = $row;
            $uid = Row::int($row, 'pid');
        }

        return $rootline;
    }

    /**
     * Non-deleted default-language children of the given pages.
     *
     * @param list<int> $parents
     *
     * @return list<array<string, mixed>>
     */
    public function children(array $parents): array
    {
        if ($parents === []) {
            return [];
        }
        $rows = [];
        foreach (array_chunk($parents, 500) as $chunk) {
            $qb = $this->queryBuilder();
            $result = $qb->select('*')->from('pages')
                ->where(
                    $qb->expr()->in('pid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $qb->expr()->eq('sys_language_uid', 0),
                    $qb->expr()->eq('deleted', 0),
                    ...$this->liveOnly($qb),
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                $this->pages[Row::int($row, 'uid')] = $row;
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Non-deleted translation records of the given pages, keyed by page uid and language.
     *
     * @param list<int> $pageUids
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function translationsOf(array $pageUids): array
    {
        $byPage = [];
        foreach (array_chunk($pageUids, 500) as $chunk) {
            $qb = $this->queryBuilder();
            $result = $qb->select('*')->from('pages')
                ->where(
                    $qb->expr()->in('l10n_parent', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $qb->expr()->gt('sys_language_uid', 0),
                    $qb->expr()->eq('deleted', 0),
                    ...$this->liveOnly($qb),
                )
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                $page = Row::int($row, 'l10n_parent');
                $language = Row::int($row, 'sys_language_uid');
                $byPage[$page][$language] = $row;
                $this->translations[$page . ':' . $language] = $row;
            }
        }

        return $byPage;
    }

    public function reset(): void
    {
        $this->pages = [];
        $this->translations = [];
    }

    /**
     * Only live records — when the table is workspace-aware at all.
     *
     * @return list<string>
     */
    private function liveOnly(QueryBuilder $qb): array
    {
        return Tca::ctrlFlag('pages', 'versioningWS') ? [$qb->expr()->eq('t3ver_wsid', 0)] : [];
    }

    private function queryBuilder(): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();

        return $qb;
    }
}
