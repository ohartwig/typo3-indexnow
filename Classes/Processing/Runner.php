<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Processing;

use Koh\IndexNow\Typo3\Activation\ActivationGate;
use Koh\IndexNow\Typo3\Page\PageUrlResolver;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * One complete run, in the order that matters: find what became due by time,
 * queue go-live submissions, then send.
 *
 * Guarded by a non-blocking lock. The scheduler already refuses to start a
 * task twice, but a person running indexnow:run by hand next to it would
 * otherwise send the same rows twice.
 */
class Runner
{
    public function __construct(
        private readonly ScheduledContentScanner $scanner,
        private readonly FullSubmission $fullSubmission,
        private readonly QueueProcessor $processor,
        private readonly PageUrlResolver $resolver,
        private readonly ActivationGate $gate,
        private readonly LockFactory $lockFactory,
    ) {}

    /** @return RunReport|null null when another run holds the lock */
    public function run(int $limit = 10000, ?int $now = null): ?RunReport
    {
        $lock = $this->lockFactory->createLocker(
            'koh_indexnow_run',
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK,
        );
        try {
            if (!$lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        // A long-lived worker must see edits made since its last run.
        $this->resolver->reset();
        $this->gate->reset();

        try {
            $report = new RunReport();
            $report->scheduled = $this->scanner->scan($now);
            $report->fullSubmissions = $this->fullSubmission->queuePending($now);
            $this->processor->process($report, $limit, $now);

            return $report;
        } finally {
            $lock->release();
        }
    }
}
