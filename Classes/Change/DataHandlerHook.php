<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Change;

use Koh\IndexNow\Typo3\Support\Tca;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Listens to every save, move and delete in the live workspace.
 *
 * Changes inside a workspace are drafts and are ignored here; they are picked
 * up when they are published (RecordPublishedListener). Records stored in a
 * workspace are never looked at, so a draft cannot leak its URL.
 */
final class DataHandlerHook
{
    /**
     * Fields of a page record whose change affects every language of it.
     * Anything else — a title, an abstract — changes only the edited language.
     */
    private const PAGE_FIELDS_AFFECTING_ALL_LANGUAGES = [
        'hidden',
        'deleted',
        'starttime',
        'endtime',
        'fe_group',
        'extendToSubpages',
        'doktype',
        'no_index',
        'canonical_link',
    ];

    private const COMMANDS_RESOLVED_BEFOREHAND = ['move', 'delete'];

    private const COMMANDS_RESOLVED_AFTERWARDS = ['move', 'undelete'];

    public function __construct(private readonly ChangeCollector $collector) {}

    /** @param array<string, mixed> $fieldArray */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, string|int $id, array $fieldArray, DataHandler $dataHandler): void
    {
        if (!self::isLive($dataHandler)) {
            return;
        }
        if ($status === 'update' && self::onlyBookkeeping($table, $fieldArray)) {
            return;
        }
        $uid = is_numeric($id) ? (int) $id : (int) ($dataHandler->substNEWwithIDs[$id] ?? 0);
        $allLanguages = $table === 'pages'
            && array_intersect_key($fieldArray, array_flip(self::PAGE_FIELDS_AFFECTING_ALL_LANGUAGES)) !== [];

        $this->collector->recordChanged($table, $uid, $allLanguages);
    }

    public function processCmdmap_preProcess(string $command, string $table, string|int $id, mixed $value, DataHandler $dataHandler): void
    {
        if (self::isLive($dataHandler) && in_array($command, self::COMMANDS_RESOLVED_BEFOREHAND, true)) {
            $this->collector->recordAboutToChange($table, (int) $id);
        }
    }

    public function processCmdmap_postProcess(string $command, string $table, string|int $id, mixed $value, DataHandler $dataHandler): void
    {
        if (self::isLive($dataHandler) && in_array($command, self::COMMANDS_RESOLVED_AFTERWARDS, true)) {
            $this->collector->recordChanged($table, (int) $id, true);
        }
    }

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $this->collector->flush();
    }

    /** Also runs after a workspace publish, which is a command. */
    public function processCmdmap_afterFinish(DataHandler $dataHandler): void
    {
        $this->collector->flush();
    }

    /**
     * True when an update touched nothing a visitor could see.
     *
     * Saving a page makes the DataHandler refresh the translation's
     * l10n_state, l10n_diffsource and tstamp. That is TYPO3 keeping its books,
     * not a change of the translated page, and announcing it would re-submit
     * every language on every save.
     *
     * @param array<string, mixed> $fieldArray
     */
    private static function onlyBookkeeping(string $table, array $fieldArray): bool
    {
        $bookkeeping = array_filter([
            Tca::ctrlString($table, 'tstamp'),
            Tca::ctrlString($table, 'crdate'),
            Tca::ctrlString($table, 'transOrigDiffSourceField'),
            Tca::ctrlString($table, 'origUid'),
            'l10n_state',
        ], static fn(string $field): bool => $field !== '');

        return array_diff_key($fieldArray, array_flip($bookkeeping)) === [];
    }

    private static function isLive(DataHandler $dataHandler): bool
    {
        return (int) ($dataHandler->BE_USER->workspace ?? 0) === 0;
    }
}
