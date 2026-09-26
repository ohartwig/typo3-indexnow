<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Queue;

/**
 * A page in one language, identified by the default-language page uid.
 *
 * The queue stores references, not URLs: a URL resolved when the editor
 * saves may be wrong by the time the queue runs (a slug edited twice, a
 * redirect added), and resolving late costs nothing because the router works
 * from the raw record anyway.
 */
final readonly class PageRef
{
    public function __construct(
        public int $page,
        public int $language,
    ) {}

    public function key(): string
    {
        return $this->page . ':' . $this->language;
    }
}
