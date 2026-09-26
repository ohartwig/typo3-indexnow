<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Support;

/**
 * Typed reads from raw database rows.
 *
 * Drivers return integers as strings, NULL for unset nullable columns and
 * nothing at all for columns a table does not have (no_index without
 * EXT:seo). One definition of "what does this field mean as an int" instead
 * of a cast at every use.
 */
final class Row
{
    /** @param array<array-key, mixed> $row */
    public static function int(array $row, string $field): int
    {
        $value = $row[$field] ?? null;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    /** @param array<array-key, mixed> $row */
    public static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;

        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    /** @param array<array-key, mixed> $row */
    public static function bool(array $row, string $field): bool
    {
        return self::int($row, $field) !== 0;
    }
}
