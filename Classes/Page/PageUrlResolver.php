<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Page;

use Koh\IndexNow\HostName;
use Koh\IndexNow\Typo3\Activation\SiteSettings;
use Koh\IndexNow\Typo3\Queue\PageRef;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use Koh\IndexNow\Typo3\Support\Row;

/**
 * Turns a page reference into the public URL it has — or had — or into nothing.
 *
 * Two rule sets:
 *
 *  - lenient, for changes: the page may be hidden, deleted, expired or
 *    noindex now, because that is exactly what the engine has to learn;
 *    but not scheduled: a page whose start time lies ahead has no public
 *    URL yet, and announcing it would publish it early. The scheduled scan
 *    queues it when the start time passes;
 *  - strict, for the full submission: only pages that are visible,
 *    indexable and not canonicalised elsewhere.
 *
 * Both refuse the same three things: page types that are not documents
 * (folders, links, shortcuts, ...), access-restricted pages — announcing one
 * would publish a URL that was meant to stay private — and languages the site
 * does not serve.
 *
 * URLs are generated from the raw record. PageRouter::generateUri() accepts a
 * page array and then does not look the page up again, which is what makes a
 * URL for a deleted page possible at all.
 */
class PageUrlResolver
{
    /** @var array<int, Site|null> */
    private array $siteByRoot = [];

    public function __construct(
        private readonly PageData $pages,
        private readonly SiteFinder $siteFinder,
    ) {}

    public function resolve(PageRef $ref, bool $strict = false, ?int $now = null): ?ResolvedPage
    {
        $now ??= time();
        $page = $this->pages->page($ref->page);
        if ($page === null || Row::int($page, 'sys_language_uid') !== 0) {
            return null;
        }

        $rootline = $this->pages->rootline($ref->page);
        $site = $this->siteOf($rootline);
        if ($site === null) {
            return null;
        }
        $language = $this->languageOf($site, $ref->language);
        if ($language === null) {
            return null;
        }
        if (in_array(Row::int($page, 'doktype'), SiteSettings::excludedDoktypes($site), true)) {
            return null;
        }

        $record = $ref->language === 0 ? $page : $this->pages->translation($ref->page, $ref->language);
        if ($record === null) {
            return null;
        }

        if ($this->isAccessRestricted($page, $record, $rootline)) {
            return null;
        }
        if ($this->isScheduled($page, $record, $rootline, $now)) {
            return null;
        }
        if ($strict && !$this->isPubliclyIndexable($page, $record, $rootline, $now)) {
            return null;
        }

        $url = $this->generateUrl($site, $language, $page, $record);
        if ($url === null) {
            return null;
        }

        return new ResolvedPage($site, $url, HostName::normalize((string) parse_url($url, PHP_URL_HOST)), Row::int($page, 'pid'));
    }

    public function reset(): void
    {
        $this->siteByRoot = [];
        $this->pages->reset();
    }

    /**
     * The site whose root is the nearest ancestor with a site configuration.
     *
     * @param list<array<string, mixed>> $rootline
     */
    private function siteOf(array $rootline): ?Site
    {
        foreach ($rootline as $row) {
            $uid = Row::int($row, 'uid');
            if (!array_key_exists($uid, $this->siteByRoot)) {
                try {
                    $this->siteByRoot[$uid] = $this->siteFinder->getSiteByRootPageId($uid);
                } catch (SiteNotFoundException) {
                    $this->siteByRoot[$uid] = null;
                }
            }
            if ($this->siteByRoot[$uid] !== null) {
                return $this->siteByRoot[$uid];
            }
        }

        return null;
    }

    private function languageOf(Site $site, int $languageId): ?SiteLanguage
    {
        foreach ($site->getLanguages() as $language) {
            if ($language->getLanguageId() === $languageId) {
                return $language;
            }
        }

        return null;
    }

    /**
     * Restricted by a frontend user group on the page, its translation or an
     * ancestor that extends its restriction to subpages.
     *
     * @param array<string, mixed> $page
     * @param array<string, mixed> $record
     * @param list<array<string, mixed>> $rootline
     */
    private function isAccessRestricted(array $page, array $record, array $rootline): bool
    {
        if (self::hasGroupRestriction($page) || self::hasGroupRestriction($record)) {
            return true;
        }
        foreach (array_slice($rootline, 1) as $ancestor) {
            if (Row::bool($ancestor, 'extendToSubpages') && self::hasGroupRestriction($ancestor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $record
     * @param list<array<string, mixed>> $rootline
     */
    private function isPubliclyIndexable(array $page, array $record, array $rootline, int $now): bool
    {
        foreach ([$page, $record] as $row) {
            if (!self::isVisible($row, $now)) {
                return false;
            }
        }
        // The translation carries its own SEO fields; the default page's are irrelevant for it.
        if (Row::bool($record, 'no_index') || trim(Row::string($record, 'canonical_link')) !== '') {
            return false;
        }
        foreach (array_slice($rootline, 1) as $ancestor) {
            if (Row::bool($ancestor, 'extendToSubpages') && !self::isVisible($ancestor, $now)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Not live yet: the page, its translation or an ancestor that extends its
     * settings to subpages starts in the future.
     *
     * @param array<string, mixed> $page
     * @param array<string, mixed> $record
     * @param list<array<string, mixed>> $rootline
     */
    private function isScheduled(array $page, array $record, array $rootline, int $now): bool
    {
        if (Row::int($page, 'starttime') > $now || Row::int($record, 'starttime') > $now) {
            return true;
        }
        foreach (array_slice($rootline, 1) as $ancestor) {
            if (Row::bool($ancestor, 'extendToSubpages') && Row::int($ancestor, 'starttime') > $now) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private static function isVisible(array $row, int $now): bool
    {
        $start = Row::int($row, 'starttime');
        $end = Row::int($row, 'endtime');

        return !Row::bool($row, 'deleted')
            && !Row::bool($row, 'hidden')
            && ($start === 0 || $start <= $now)
            && ($end === 0 || $end > $now);
    }

    /** @param array<string, mixed> $row */
    private static function hasGroupRestriction(array $row): bool
    {
        $groups = trim(Row::string($row, 'fe_group'));

        return $groups !== '' && $groups !== '0';
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $record
     */
    private function generateUrl(Site $site, SiteLanguage $language, array $page, array $record): ?string
    {
        $route = [
            'uid' => Row::int($page, 'uid'),
            'pid' => Row::int($page, 'pid'),
            'sys_language_uid' => $language->getLanguageId(),
            'l10n_parent' => $language->getLanguageId() === 0 ? 0 : Row::int($page, 'uid'),
            'slug' => Row::string($record, 'slug'),
        ];
        if ($language->getLanguageId() !== 0) {
            $route['_LOCALIZED_UID'] = Row::int($record, 'uid');
        }

        try {
            $uri = (string) $site->getRouter()->generateUri($route, ['_language' => $language]);
        } catch (\Throwable) {
            // A slug the router cannot build a URL from is not worth failing
            // the whole run for; the page is simply not announced.
            return null;
        }

        return str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://') ? $uri : null;
    }
}
