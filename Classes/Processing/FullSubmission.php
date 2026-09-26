<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Processing;

use Koh\IndexNow\Typo3\Activation\ActivationGate;
use Koh\IndexNow\Typo3\Page\PageData;
use Koh\IndexNow\Typo3\Queue\PageQueue;
use Koh\IndexNow\Typo3\Queue\PageRef;
use Koh\IndexNow\Typo3\Queue\Reason;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use Koh\IndexNow\Typo3\Support\Row;

/**
 * Announces a whole site once, the first time one of its hosts is active.
 *
 * That is the go-live: a new site, or an existing one that just got the set.
 * Without it, only pages edited from then on would ever be announced.
 *
 * The walk queues every page of the tree in every language it has; the
 * strict rules in PageUrlResolver decide at send time which of them are meant
 * to be found. It stops at the root of a nested site, which that site
 * announces itself.
 */
class FullSubmission
{
    private const REGISTRY_NAMESPACE = 'koh_indexnow';

    public function __construct(
        private readonly PageData $pages,
        private readonly PageQueue $queue,
        private readonly ActivationGate $gate,
        private readonly SiteFinder $siteFinder,
        private readonly Registry $registry,
    ) {}

    /**
     * Queue every active site that has a host not announced before.
     *
     * @return array<string, int> site identifier => pages queued
     */
    public function queuePending(?int $now = null): array
    {
        $queued = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $pendingHosts = array_filter(
                array_keys($this->gate->hostsOf($site)),
                fn(string $host): bool => $this->gate->forHost($site, $host)->active && !$this->isDone($host),
            );
            if ($pendingHosts === []) {
                continue;
            }
            $queued[$site->getIdentifier()] = $this->queueSite($site, $now);
            foreach ($pendingHosts as $host) {
                $this->registry->set(self::REGISTRY_NAMESPACE, 'fullSubmission:' . $host, $now ?? time());
            }
        }

        return $queued;
    }

    /** Queue a site regardless of whether it was announced before — indexnow:submit-all. */
    public function queueSite(Site $site, ?int $now = null): int
    {
        $otherRoots = [];
        foreach ($this->siteFinder->getAllSites() as $other) {
            if ($other->getRootPageId() !== $site->getRootPageId()) {
                $otherRoots[$other->getRootPageId()] = true;
            }
        }

        $root = $this->pages->page($site->getRootPageId());
        if ($root === null || Row::bool($root, 'deleted')) {
            return 0;
        }

        $refs = [];
        $level = [$site->getRootPageId()];
        $visited = [];
        while ($level !== []) {
            foreach ($level as $uid) {
                $visited[$uid] = true;
                $refs[] = new PageRef($uid, 0);
            }
            foreach ($this->pages->translationsOf($level) as $page => $translations) {
                foreach (array_keys($translations) as $language) {
                    $refs[] = new PageRef($page, $language);
                }
            }
            $next = [];
            foreach ($this->pages->children($level) as $child) {
                $uid = Row::int($child, 'uid');
                if (!isset($visited[$uid]) && !isset($otherRoots[$uid])) {
                    $next[] = $uid;
                }
            }
            $level = $next;
        }

        return $this->queue->enqueue($refs, Reason::Full, $now);
    }

    private function isDone(string $host): bool
    {
        return $this->registry->get(self::REGISTRY_NAMESPACE, 'fullSubmission:' . $host) !== null;
    }
}
