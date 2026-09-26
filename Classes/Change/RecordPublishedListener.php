<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Change;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Workspaces\Event\AfterRecordPublishedEvent;

/**
 * A workspace publish is the moment a draft becomes public.
 *
 * The event carries the live uid. The queue is written by the DataHandler
 * hook's processCmdmap_afterFinish, which runs after every publish command;
 * without EXT:workspaces the event never fires and nothing here matters.
 */
#[AsEventListener(identifier: 'koh-indexnow/record-published', event: AfterRecordPublishedEvent::class)]
final readonly class RecordPublishedListener
{
    public function __construct(private ChangeCollector $collector) {}

    public function __invoke(AfterRecordPublishedEvent $event): void
    {
        $this->collector->recordChanged($event->getTable(), $event->getRecordId(), $event->getTable() === 'pages');
    }
}
