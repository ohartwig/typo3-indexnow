<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Page;

use TYPO3\CMS\Core\Site\Entity\Site;

final readonly class ResolvedPage
{
    public function __construct(
        public Site $site,
        public string $url,
        public string $host,
        public int $parentPage,
    ) {}
}
