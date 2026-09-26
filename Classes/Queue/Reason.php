<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Queue;

/**
 * Why a page is in the queue, which decides how strictly it is filtered.
 *
 * A change or a scheduled start/end is announced even when the page is now
 * hidden, deleted or noindex: the engine has to re-crawl to learn that, and
 * IndexNow asks for removals explicitly. The full submission at go-live only
 * lists pages that are meant to be found.
 */
enum Reason: string
{
    case Change = 'change';
    case Schedule = 'schedule';
    case Full = 'full';

    public function isStrict(): bool
    {
        return $this === self::Full;
    }
}
