<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Processing;

use Koh\IndexNow\Exception\HostNotAllowed;
use Koh\IndexNow\Exception\InvalidUrl;
use Koh\IndexNow\Result;
use Koh\IndexNow\Submission;
use Koh\IndexNow\UrlCollection;
use Koh\IndexNow\Typo3\Activation\Activation;
use Koh\IndexNow\Typo3\Activation\ActivationGate;
use Koh\IndexNow\Typo3\Activation\SiteSettings;
use Koh\IndexNow\Typo3\Event\AfterUrlsSubmittedEvent;
use Koh\IndexNow\Typo3\Event\BeforeUrlsSubmittedEvent;
use Koh\IndexNow\Typo3\Http\ClientFactory;
use Koh\IndexNow\Typo3\Page\PageUrlResolver;
use Koh\IndexNow\Typo3\Queue\PageQueue;
use Koh\IndexNow\Typo3\Queue\PageRef;
use Koh\IndexNow\Typo3\Queue\QueueItem;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Sends what is due: resolve, group by host, one request per host, settle the rows.
 *
 * Settling is per host and all-or-nothing. When a host's request is accepted
 * its rows go; when it fails in a way that heals (throttling, 5xx, a key file
 * the engine could not fetch yet, no connection) they wait with exponential
 * backoff, honouring Retry-After; when it fails in a way that will not heal
 * (400/422) they go, loudly, because sending them again would fail again.
 * Rows that resolve to nothing — a folder, a restricted page, a host that
 * does not announce — are dropped without a request: a development system
 * must not grow a queue that nobody will ever drain.
 */
class QueueProcessor
{
    public const MAX_ATTEMPTS = 8;

    private const BASE_BACKOFF = 60;
    private const MAX_BACKOFF = 6 * 3600;

    public function __construct(
        private readonly PageQueue $queue,
        private readonly PageUrlResolver $resolver,
        private readonly ActivationGate $gate,
        private readonly ClientFactory $clients,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(RunReport $report, int $limit = 10000, ?int $now = null): void
    {
        $now ??= time();
        $items = $this->queue->due($limit, $now);
        $report->processed += count($items);

        /** @var array<string, array{site: Site, activation: Activation, urls: array<string, true>, items: list<QueueItem>}> $groups */
        $groups = [];
        $drop = [];
        foreach ($items as $item) {
            $resolved = $this->resolver->resolve($item->ref, $item->reason->isStrict(), $now);
            if ($resolved === null) {
                $drop[] = $item->uid;
                continue;
            }
            $activation = $this->gate->forHost($resolved->site, $resolved->host);
            if (!$activation->active) {
                $drop[] = $item->uid;
                continue;
            }

            $groups[$resolved->host] ??= ['site' => $resolved->site, 'activation' => $activation, 'urls' => [], 'items' => []];
            $groups[$resolved->host]['urls'][$resolved->url] = true;
            $groups[$resolved->host]['items'][] = $item;

            if (!$item->reason->isStrict() && SiteSettings::includeParentPage($resolved->site)) {
                $parent = $this->resolver->resolve(new PageRef($resolved->parentPage, $item->ref->language), false, $now);
                if ($parent !== null && $parent->host === $resolved->host) {
                    $groups[$resolved->host]['urls'][$parent->url] = true;
                }
            }
        }

        $this->queue->remove($drop);
        $report->dropped += count($drop);

        foreach ($groups as $host => $group) {
            $this->submitHost($report, $host, $group['site'], $group['activation'], array_keys($group['urls']), $group['items'], $now);
        }
    }

    /**
     * @param list<string> $urls
     * @param list<QueueItem> $items
     */
    private function submitHost(RunReport $report, string $host, Site $site, Activation $activation, array $urls, array $items, int $now): void
    {
        $uids = array_map(static fn(QueueItem $item): int => $item->uid, $items);

        $event = new BeforeUrlsSubmittedEvent($site, $host, $urls);
        $this->eventDispatcher->dispatch($event);
        $collection = UrlCollection::of($event->getUrls());
        foreach ($collection->rejected() as $url => $reason) {
            $this->logger->warning('IndexNow: not announcing {url}: {reason}', ['url' => $url, 'reason' => $reason]);
        }
        $urls = $collection->urlsFor($host);

        if ($urls === [] || $activation->key === null || $activation->hostPolicy === null) {
            $this->queue->remove($uids);

            return;
        }

        try {
            $submission = Submission::forHost($host, $activation->key, $urls, $activation->keyLocation($host));
            $results = $this->clients->create($activation->hostPolicy)->submitAll($submission);
        } catch (HostNotAllowed | InvalidUrl $e) {
            // The gate and the collection already checked both; reaching this
            // means they disagree with the library, which must not end in a retry loop.
            $this->queue->remove($uids);
            $report->failed[$host] = $e->getMessage();
            $this->logger->error('IndexNow: {host} refused before sending: {message}', ['host' => $host, 'message' => $e->getMessage()]);

            return;
        }

        $this->eventDispatcher->dispatch(new AfterUrlsSubmittedEvent($site, $host, $urls, $results));

        $last = $results === [] ? null : $results[array_key_last($results)];
        if ($last === null || ($last->isSuccess() && count($results) === count($submission->chunks()))) {
            $this->queue->remove($uids);
            $report->submitted[$host] = ($report->submitted[$host] ?? 0) + count($urls);
            $this->logger->info('IndexNow: announced {count} URLs for {host} ({outcome})', [
                'count' => count($urls),
                'host' => $host,
                'outcome' => $last?->outcome->value ?? 'nothing to send',
            ]);

            return;
        }

        if ($last->isRetryable()) {
            $this->defer($report, $host, $items, $last, $now);

            return;
        }

        $this->queue->remove($uids);
        $report->failed[$host] = self::describe($last);
        $this->logger->error('IndexNow: {host} rejected {count} URLs, not retrying: {detail}', [
            'host' => $host,
            'count' => count($urls),
            'detail' => self::describe($last),
        ]);
    }

    /** @param list<QueueItem> $items */
    private function defer(RunReport $report, string $host, array $items, Result $result, int $now): void
    {
        $givenUp = [];
        foreach ($items as $item) {
            $attempts = $item->attempts + 1;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $givenUp[] = $item->uid;
                continue;
            }
            $backoff = min(self::MAX_BACKOFF, self::BASE_BACKOFF << min($item->attempts, 16));
            $this->queue->defer($item->uid, $attempts, $now + max($backoff, $result->retryAfterSeconds ?? 0));
        }
        $this->queue->remove($givenUp);

        $report->deferred[$host] = self::describe($result);
        $level = $givenUp === [] ? 'warning' : 'error';
        $this->logger->log($level, 'IndexNow: {host} deferred ({detail}); {givenUp} pages given up after {max} attempts', [
            'host' => $host,
            'detail' => self::describe($result),
            'givenUp' => count($givenUp),
            'max' => self::MAX_ATTEMPTS,
        ]);
    }

    private static function describe(Result $result): string
    {
        return trim(sprintf('%s%s %s', $result->outcome->value, $result->statusCode !== null ? ' (HTTP ' . $result->statusCode . ')' : '', $result->detail));
    }
}
