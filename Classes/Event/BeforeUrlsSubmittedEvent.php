<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Event;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The URLs about to be announced for one host, before anything is sent.
 *
 * The place for URLs that are not pages but change with them: a feed, an
 * llms.txt, a sitemap of a record type. URLs on other hosts are dropped
 * afterwards, so a listener cannot widen what a site announces.
 */
final class BeforeUrlsSubmittedEvent
{
    /** @param list<string> $urls */
    public function __construct(
        public readonly Site $site,
        public readonly string $host,
        private array $urls,
    ) {}

    /** @return list<string> */
    public function getUrls(): array
    {
        return $this->urls;
    }

    /** @param list<string> $urls */
    public function setUrls(array $urls): void
    {
        $this->urls = $urls;
    }
}
