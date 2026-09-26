<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Support;

/** Typed reads of the TCA "ctrl" section. */
final class Tca
{
    /** @return list<string> */
    public static function tables(): array
    {
        $tca = $GLOBALS['TCA'] ?? null;

        return is_array($tca) ? array_values(array_filter(array_keys($tca), is_string(...))) : [];
    }

    public static function has(string $table): bool
    {
        return self::ctrl($table) !== null;
    }

    /** @return array<array-key, mixed>|null */
    public static function ctrl(string $table): ?array
    {
        $tca = $GLOBALS['TCA'] ?? null;
        $ctrl = is_array($tca) && is_array($tca[$table] ?? null) ? ($tca[$table]['ctrl'] ?? null) : null;

        return is_array($ctrl) ? $ctrl : null;
    }

    public static function ctrlString(string $table, string $key): string
    {
        return Row::string(self::ctrl($table) ?? [], $key);
    }

    public static function ctrlFlag(string $table, string $key): bool
    {
        $value = (self::ctrl($table) ?? [])[$key] ?? false;

        return is_bool($value) ? $value : Row::bool([$key => $value], $key);
    }

    public static function enableColumn(string $table, string $key): string
    {
        $columns = (self::ctrl($table) ?? [])['enablecolumns'] ?? null;

        return is_array($columns) ? Row::string($columns, $key) : '';
    }
}
