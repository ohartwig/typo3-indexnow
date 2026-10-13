<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Tests\Functional;

use Koh\IndexNow\Key;
use Koh\IndexNow\Typo3\Activation\ActivationGate;
use Koh\IndexNow\Typo3\Change\RecordPageMapper;
use Koh\IndexNow\Typo3\Page\PageData;
use Koh\IndexNow\Typo3\Page\PageUrlResolver;
use Koh\IndexNow\Typo3\Processing\FullSubmission;
use Koh\IndexNow\Typo3\Processing\QueueProcessor;
use Koh\IndexNow\Typo3\Processing\RunReport;
use Koh\IndexNow\Typo3\Processing\ScheduledContentScanner;
use Koh\IndexNow\Typo3\Queue\PageQueue;
use Koh\IndexNow\Typo3\Queue\PageRef;
use Koh\IndexNow\Typo3\Queue\Reason;
use Koh\IndexNow\Typo3\Setup\ProvisionSchedulerTask;
use Koh\IndexNow\Typo3\Support\Row;
use Koh\IndexNow\Typo3\Tests\Functional\Support\RecordingClientFactory;
use Koh\IndexNow\Typo3\Tests\Functional\Support\RecordingHttpClient;
use Koh\IndexNow\Typo3\Tests\Unit\ProductionContext;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Package\Event\PackageInitializationEvent;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class IndexNowTest extends FunctionalTestCase
{
    use ProductionContext;

    private const ENCRYPTION_KEY = 'f00dfeedf00dfeedf00dfeedf00dfeedf00dfeedf00dfeedf00dfeedf00dfeed';

    protected array $coreExtensionsToLoad = ['seo', 'scheduler', 'workspaces'];

    protected array $testExtensionsToLoad = ['koh/typo3-indexnow'];

    protected array $configurationToUseInTestInstance = [
        'SYS' => ['encryptionKey' => self::ENCRYPTION_KEY],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->writeSite('https://shop-domain.de/');
        $this->useContext('Production');
    }

    #[Test]
    public function theGoLiveSubmissionListsExactlyThePagesMeantToBeFound(): void
    {
        $http = new RecordingHttpClient();
        $this->fullSubmission()->queuePending();
        $report = $this->process($http);

        self::assertCount(1, $http->requests);
        $payload = $http->payload();
        self::assertSame('shop-domain.de', $payload['host']);
        self::assertSame(Key::derive(self::ENCRYPTION_KEY, 'shop-domain.de')->value, $payload['key']);
        self::assertEqualsCanonicalizing([
            'https://shop-domain.de/',
            'https://shop-domain.de/leistungen',
            'https://shop-domain.de/leistungen/beratung',
            'https://shop-domain.de/beitrag',
            'https://shop-domain.de/en/',
            'https://shop-domain.de/en/services',
            'https://shop-domain.de/en/services/consulting',
        ], $payload['urlList'], 'no folder, no restricted page or child, nothing hidden, noindex or not yet started');
        self::assertSame([], $this->queueRows());
        self::assertSame(['shop-domain.de' => 7], $report->submitted);
    }

    #[Test]
    public function theGoLiveSubmissionHappensOncePerHost(): void
    {
        self::assertSame(['main' => 13], $this->fullSubmission()->queuePending());
        $this->process(new RecordingHttpClient());

        self::assertSame([], $this->fullSubmission()->queuePending());
    }

    #[Test]
    public function aHiddenPageIsStillAnnouncedWhenItChangesSoTheEngineLearnsItIsGone(): void
    {
        $this->queue()->enqueue([new PageRef(5, 0)], Reason::Change);
        $http = new RecordingHttpClient();
        $this->process($http);

        self::assertEqualsCanonicalizing(
            ['https://shop-domain.de/versteckt', 'https://shop-domain.de/'],
            $http->payload()['urlList'],
            'the changed page plus the page listing it',
        );
    }

    #[Test]
    public function aScheduledPageIsNotAnnouncedBeforeItsStartTime(): void
    {
        // A post prepared in a workspace and published with a start time ahead
        // went out at once (2026-10-13): changes skip the visibility rules.
        $this->queue()->enqueue([new PageRef(10, 0)], Reason::Change);
        $http = new RecordingHttpClient();
        $report = $this->process($http);

        self::assertSame([], $http->requests);
        self::assertSame(1, $report->dropped);
        self::assertSame([], $this->queueRows());
    }

    #[Test]
    public function publishingContentOfAScheduledPageAnnouncesNothing(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content', ['pid' => 10], ['uid' => 4]);
        $this->dataHandler(['tt_content' => [4 => ['header' => 'Draft']]], [], 1);
        $qb = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        $version = $qb->select('uid')->from('tt_content')
            ->where($qb->expr()->eq('t3ver_oid', 4), $qb->expr()->eq('t3ver_wsid', 1))
            ->executeQuery()->fetchOne();
        self::assertIsNumeric($version);
        $this->dataHandler([], ['tt_content' => [4 => ['version' => ['action' => 'publish', 'swapWith' => (int) $version]]]], 1);
        self::assertSame(['10:0'], $this->queuedKeys());

        $http = new RecordingHttpClient();
        $this->process($http);

        self::assertSame([], $http->requests);
    }

    #[Test]
    public function aScheduledPageIsAnnouncedOnceItsStartTimeHasPassed(): void
    {
        $this->queue()->enqueue([new PageRef(10, 0)], Reason::Schedule);
        $http = new RecordingHttpClient();
        $this->process($http, 4102444900);

        self::assertContains('https://shop-domain.de/geplant', $http->payload()['urlList']);
    }

    #[Test]
    public function aPageUnderAScheduledSectionIsNotAnnouncedEarly(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['starttime' => 4102444800, 'extendToSubpages' => 1], ['uid' => 2]);
        $this->queue()->enqueue([new PageRef(7, 0)], Reason::Change);
        $http = new RecordingHttpClient();
        $this->process($http);

        self::assertSame([], $http->requests);
    }

    #[Test]
    public function aRestrictedPageIsNeverAnnouncedNotEvenWhenItChanges(): void
    {
        $this->queue()->enqueue([new PageRef(4, 0), new PageRef(8, 0)], Reason::Change);
        $http = new RecordingHttpClient();
        $report = $this->process($http);

        self::assertSame([], $http->requests);
        self::assertSame(2, $report->dropped);
        self::assertSame([], $this->queueRows());
    }

    #[Test]
    public function aDeletedPageKeepsTheUrlItHad(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['deleted' => 1], ['uid' => 7]);
        $this->queue()->enqueue([new PageRef(7, 0)], Reason::Change);
        $http = new RecordingHttpClient();
        $this->process($http);

        self::assertContains('https://shop-domain.de/leistungen/beratung', $http->payload()['urlList']);
    }

    #[Test]
    public function nothingLeavesANonProductionSystemAndTheQueueDoesNotGrow(): void
    {
        $this->useContext('Development');
        $this->queue()->enqueue([new PageRef(2, 0), new PageRef(2, 1)], Reason::Change);
        $http = new RecordingHttpClient();
        $report = $this->process($http);

        self::assertSame([], $http->requests);
        self::assertSame(2, $report->dropped);
        self::assertSame([], $this->queueRows());
    }

    #[Test]
    public function aDevelopmentHostIsDroppedInProductionToo(): void
    {
        $this->writeSite('https://shop.dev.shop-domain.de/');
        $this->queue()->enqueue([new PageRef(2, 0)], Reason::Change);
        $http = new RecordingHttpClient();
        $this->process($http);

        self::assertSame([], $http->requests);
        self::assertSame([], $this->queueRows());
    }

    #[Test]
    public function throttlingIsRetriedLaterWithBackoff(): void
    {
        $this->queue()->enqueue([new PageRef(2, 0)], Reason::Change, 1000);
        $report = $this->process(new RecordingHttpClient([429]), 1000);

        $rows = $this->queueRows();
        self::assertCount(1, $rows);
        self::assertSame(1, Row::int($rows[0], 'attempts'));
        self::assertSame(1060, Row::int($rows[0], 'not_before'));
        self::assertArrayHasKey('shop-domain.de', $report->deferred);

        // Not due yet: nothing is sent before the backoff has passed.
        $http = new RecordingHttpClient();
        $this->process($http, 1030);
        self::assertSame([], $http->requests);
    }

    #[Test]
    public function aRetryableFailureIsGivenUpAfterTheMaximumAttempts(): void
    {
        $this->queue()->enqueue([new PageRef(2, 0)], Reason::Change, 0);
        $this->getConnectionPool()->getConnectionForTable(PageQueue::TABLE)
            ->update(PageQueue::TABLE, ['attempts' => QueueProcessor::MAX_ATTEMPTS - 1], ['page' => 2]);

        $this->process(new RecordingHttpClient([503]));

        self::assertSame([], $this->queueRows());
    }

    #[Test]
    public function aRejectionIsNotRetried(): void
    {
        $this->queue()->enqueue([new PageRef(2, 0)], Reason::Change);
        $report = $this->process(new RecordingHttpClient([422]));

        self::assertSame([], $this->queueRows());
        self::assertTrue($report->hasFailures());
    }

    #[Test]
    public function savingContentQueuesItsPageInItsLanguage(): void
    {
        $this->dataHandler(['tt_content' => [2 => ['header' => 'Changed']]]);

        self::assertSame(['2:1'], $this->queuedKeys());
    }

    #[Test]
    public function contentForAllLanguagesQueuesEveryLanguageOfThePage(): void
    {
        $this->dataHandler(['tt_content' => [3 => ['header' => 'Changed']]]);

        self::assertSame(['7:0', '7:1'], $this->queuedKeys());
    }

    #[Test]
    public function hidingAPageQueuesAllItsLanguagesButATitleChangeOnlyItsOwn(): void
    {
        $this->dataHandler(['pages' => [2 => ['title' => 'Neu']]]);
        self::assertSame(['2:0'], $this->queuedKeys());

        $this->dataHandler(['pages' => [2 => ['hidden' => 1]]]);
        self::assertSame(['2:0', '2:1'], $this->queuedKeys());
    }

    #[Test]
    public function aMovedElementQueuesTheOldAndTheNewPage(): void
    {
        $this->dataHandler([], ['tt_content' => [1 => ['move' => 7]]]);

        self::assertSame(['2:0', '7:0'], $this->queuedKeys());
    }

    #[Test]
    public function draftsInAWorkspaceQueueNothing(): void
    {
        $this->dataHandler(['tt_content' => [1 => ['header' => 'Draft']]], [], 1);

        self::assertSame([], $this->queuedKeys());
    }

    #[Test]
    public function publishingAWorkspaceQueuesWhatBecamePublic(): void
    {
        $this->dataHandler(['tt_content' => [1 => ['header' => 'Draft']]], [], 1);
        self::assertSame([], $this->queuedKeys());

        $qb = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        $version = $qb->select('uid')->from('tt_content')
            ->where($qb->expr()->eq('t3ver_oid', 1), $qb->expr()->eq('t3ver_wsid', 1))
            ->executeQuery()->fetchOne();
        self::assertIsNumeric($version);

        $this->dataHandler([], ['tt_content' => [1 => ['version' => ['action' => 'publish', 'swapWith' => (int) $version]]]], 1);

        self::assertSame(['2:0'], $this->queuedKeys());
    }

    #[Test]
    public function aPageThatWentLiveByItsStartTimeIsQueued(): void
    {
        $registry = GeneralUtility::makeInstance(Registry::class);
        $registry->set('koh_indexnow', 'scheduledScanUntil', 4102444700);

        $queued = $this->scanner()->scan(4102444900);

        self::assertSame(1, $queued);
        self::assertSame(['10:0'], $this->queuedKeys());
    }

    #[Test]
    public function theFirstScanOnlyOpensTheWindow(): void
    {
        self::assertSame(0, $this->scanner()->scan(4102444900));
        self::assertSame([], $this->queuedKeys());
    }

    #[Test]
    public function theSchedulerTaskIsCreatedOnceAndThenLeftAlone(): void
    {
        $listener = new ProvisionSchedulerTask($this->getConnectionPool(), new NullLogger());
        $event = new PackageInitializationEvent('koh_indexnow', $this->createStub(PackageInterface::class));

        $listener($event);
        $this->getConnectionPool()->getConnectionForTable('tx_scheduler_task')->update('tx_scheduler_task', ['disable' => 1], ['tasktype' => 'indexnow:run']);
        $listener($event);

        // Without restrictions: Connection::select() would hide the disabled task.
        $qb = $this->getConnectionPool()->getQueryBuilderForTable('tx_scheduler_task');
        $qb->getRestrictions()->removeAll();
        $tasks = $qb->select('disable', 'execution_details')->from('tx_scheduler_task')
            ->where($qb->expr()->eq('tasktype', $qb->createNamedParameter('indexnow:run')))
            ->executeQuery()->fetchAllAssociative();
        self::assertCount(1, $tasks);
        self::assertSame(1, Row::int($tasks[0], 'disable'), 'a task an administrator disabled stays disabled');
        // Decoded once must give the array the scheduler's TaskSerializer expects.
        $execution = json_decode(Row::string($tasks[0], 'execution_details'), true);
        self::assertIsArray($execution);
        self::assertSame(300, $execution['interval'] ?? null);
    }

    private function writeSite(string $base): void
    {
        $this->service(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => $base,
            'dependencies' => ['koh/indexnow'],
            'languages' => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/en/'],
            ],
        ]);
        $this->siteFinder()->getAllSites(false);
    }

    private function process(RecordingHttpClient $http, ?int $now = null): RunReport
    {
        $report = new RunReport();
        (new QueueProcessor(
            $this->queue(),
            $this->resolver(),
            new ActivationGate(),
            new RecordingClientFactory($http),
            $this->eventDispatcher(),
            new NullLogger(),
        ))->process($report, 10000, $now);

        return $report;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $data
     * @param array<string, array<int, array<string, mixed>>> $commands
     */
    private function dataHandler(array $data, array $commands = [], int $workspace = 0): void
    {
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->workspace = $workspace;
        $GLOBALS['LANG'] = $this->service(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        // The hook comes from the container (it is a public service), exactly as in production.
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, $commands, $backendUser);
        $data !== [] ? $dataHandler->process_datamap() : $dataHandler->process_cmdmap();
    }

    /** @return list<string> */
    private function queuedKeys(): array
    {
        $keys = array_map(static fn(array $row): string => Row::int($row, 'page') . ':' . Row::int($row, 'language'), $this->queueRows());
        sort($keys);

        return $keys;
    }

    /** @return list<array<string, mixed>> */
    private function queueRows(): array
    {
        return $this->getConnectionPool()->getConnectionForTable(PageQueue::TABLE)
            ->select(['*'], PageQueue::TABLE)->fetchAllAssociative();
    }

    private function siteFinder(): SiteFinder
    {
        return $this->service(SiteFinder::class);
    }

    private function eventDispatcher(): EventDispatcherInterface
    {
        return $this->service(EventDispatcherInterface::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = $this->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    private function queue(): PageQueue
    {
        return new PageQueue($this->getConnectionPool());
    }

    private function mapper(): RecordPageMapper
    {
        return new RecordPageMapper($this->getConnectionPool(), new PageData($this->getConnectionPool()), $this->eventDispatcher());
    }

    private function resolver(): PageUrlResolver
    {
        return new PageUrlResolver(new PageData($this->getConnectionPool()), $this->siteFinder());
    }

    private function fullSubmission(): FullSubmission
    {
        return new FullSubmission(
            new PageData($this->getConnectionPool()),
            $this->queue(),
            new ActivationGate(),
            $this->siteFinder(),
            GeneralUtility::makeInstance(Registry::class),
        );
    }

    private function scanner(): ScheduledContentScanner
    {
        return new ScheduledContentScanner($this->getConnectionPool(), $this->mapper(), $this->queue(), GeneralUtility::makeInstance(Registry::class));
    }
}
