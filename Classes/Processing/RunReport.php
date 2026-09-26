<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Processing;

final class RunReport
{
    public int $scheduled = 0;

    /** @var array<string, int> */
    public array $fullSubmissions = [];

    public int $processed = 0;

    public int $dropped = 0;

    /** @var array<string, int> host => URLs accepted */
    public array $submitted = [];

    /** @var array<string, string> host => why it was deferred */
    public array $deferred = [];

    /** @var array<string, string> host => why it was given up */
    public array $failed = [];

    public function hasFailures(): bool
    {
        return $this->failed !== [];
    }
}
