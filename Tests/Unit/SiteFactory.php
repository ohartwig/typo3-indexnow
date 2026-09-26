<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Tests\Unit;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

final class SiteFactory
{
    /**
     * @param array<string, mixed>|null $indexNowSettings null: the site does not include the set
     * @param list<array<string, mixed>> $extraLanguages
     */
    public static function site(string $base = 'https://shop-domain.de/', ?array $indexNowSettings = [], array $extraLanguages = []): Site
    {
        $settings = $indexNowSettings === null
            ? []
            : ['indexnow' => array_replace(['enabled' => true, 'key' => '', 'excludedDoktypes' => '3, 4, 6, 7, 199, 254, 255', 'includeParentPage' => true, 'deniedHostSuffixes' => ''], $indexNowSettings)];

        return new Site('main', 1, [
            'base' => $base,
            'languages' => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/en/'],
                ...$extraLanguages,
            ],
        ], SiteSettings::createFromSettingsTree($settings));
    }
}
