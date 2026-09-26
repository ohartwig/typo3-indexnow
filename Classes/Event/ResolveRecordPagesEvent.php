<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Event;

use Koh\IndexNow\Typo3\Queue\PageRef;

/**
 * Which pages show a changed record.
 *
 * The default answer is the page the record is stored on, which is right for
 * content elements and their inline children. A record that is stored in a
 * folder and rendered elsewhere — a news item, a product, an event — needs a
 * listener that knows its detail page; that knowledge lives in the extension
 * owning the record, not here.
 */
final class ResolveRecordPagesEvent
{
    /**
     * @param array<string, mixed> $record raw record, including deleted and hidden ones
     * @param list<PageRef> $pages
     */
    public function __construct(
        public readonly string $table,
        public readonly array $record,
        private array $pages,
    ) {}

    /** @return list<PageRef> */
    public function getPages(): array
    {
        return $this->pages;
    }

    /** @param list<PageRef> $pages */
    public function setPages(array $pages): void
    {
        $this->pages = $pages;
    }

    public function addPage(PageRef $page): void
    {
        $this->pages[] = $page;
    }
}
