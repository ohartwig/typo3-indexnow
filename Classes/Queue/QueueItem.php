<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Queue;

final readonly class QueueItem
{
    public function __construct(
        public int $uid,
        public PageRef $ref,
        public Reason $reason,
        public int $attempts,
    ) {}
}
