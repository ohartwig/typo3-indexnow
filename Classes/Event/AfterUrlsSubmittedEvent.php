<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Event;

use Koh\IndexNow\Result;
use TYPO3\CMS\Core\Site\Entity\Site;

/** What was sent for one host and how the engine answered. Read-only, for monitoring. */
final readonly class AfterUrlsSubmittedEvent
{
    /**
     * @param list<string> $urls
     * @param list<Result> $results one per protocol-sized part actually sent
     */
    public function __construct(
        public Site $site,
        public string $host,
        public array $urls,
        public array $results,
    ) {}
}
