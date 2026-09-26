<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Activation;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Typed access to the settings of the koh/indexnow set.
 *
 * A site that does not include the set has none of these settings, and that
 * — not the value of indexnow.enabled — is what keeps it out: installing the
 * extension must not start announcing every site of an installation.
 */
final class SiteSettings
{
    public const DEFAULT_EXCLUDED_DOKTYPES = [3, 4, 6, 7, 199, 254, 255];

    public static function includesSet(Site $site): bool
    {
        return $site->getSettings()->has('indexnow.enabled');
    }

    public static function enabled(Site $site): bool
    {
        return (bool) $site->getSettings()->get('indexnow.enabled', false);
    }

    public static function explicitKey(Site $site): string
    {
        return trim(self::string($site, 'indexnow.key'));
    }

    /** @return list<int> */
    public static function excludedDoktypes(Site $site): array
    {
        if (!$site->getSettings()->has('indexnow.excludedDoktypes')) {
            return self::DEFAULT_EXCLUDED_DOKTYPES;
        }

        return array_map(static fn(string $doktype): int => (int) $doktype, self::csv(self::string($site, 'indexnow.excludedDoktypes')));
    }

    public static function includeParentPage(Site $site): bool
    {
        return (bool) $site->getSettings()->get('indexnow.includeParentPage', true);
    }

    /** @return list<string> */
    public static function deniedHostSuffixes(Site $site): array
    {
        return self::csv(self::string($site, 'indexnow.deniedHostSuffixes'));
    }

    private static function string(Site $site, string $identifier): string
    {
        $value = $site->getSettings()->get($identifier, '');

        return is_scalar($value) ? (string) $value : '';
    }

    /** @return list<string> */
    private static function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== ''));
    }
}
