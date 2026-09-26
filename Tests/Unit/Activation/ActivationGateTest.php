<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Tests\Unit\Activation;

use Koh\IndexNow\Key;
use Koh\IndexNow\Typo3\Activation\ActivationGate;
use Koh\IndexNow\Typo3\Tests\Unit\ProductionContext;
use Koh\IndexNow\Typo3\Tests\Unit\SiteFactory;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ActivationGateTest extends UnitTestCase
{
    use ProductionContext;

    protected bool $backupEnvironment = true;

    private const ENCRYPTION_KEY = 'f00dfeedf00dfeedf00dfeedf00dfeedf00dfeedf00dfeedf00dfeedf00dfeed';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useEncryptionKey(self::ENCRYPTION_KEY);
        $this->useContext('Production');
    }

    #[Test]
    public function installingTheExtensionAloneAnnouncesNothing(): void
    {
        $activation = (new ActivationGate())->forHost(SiteFactory::site(indexNowSettings: null), 'shop-domain.de');

        self::assertFalse($activation->active);
        self::assertStringContainsString('koh/indexnow set', $activation->reason);
    }

    #[Test]
    public function theSiteSettingSwitchesItOff(): void
    {
        self::assertFalse((new ActivationGate())->forHost(SiteFactory::site(indexNowSettings: ['enabled' => false]), 'shop-domain.de')->active);
    }

    #[Test]
    public function onlyTheProductionContextAnnounces(): void
    {
        foreach (['Development', 'Testing', 'Production/Staging'] as $context) {
            $this->useContext($context);
            $activation = (new ActivationGate())->forHost(SiteFactory::site(), 'shop-domain.de');

            // Production/Staging is a sub-context of Production and does count;
            // a staging system is kept out by its host name, not its context.
            self::assertSame($context === 'Production/Staging', $activation->active, $context);
        }
    }

    #[Test]
    public function aDevelopmentHostIsRefusedEvenInProduction(): void
    {
        $activation = (new ActivationGate())->forHost(SiteFactory::site('https://shop.dev.shop-domain.de/'), 'shop.dev.shop-domain.de');

        self::assertFalse($activation->active);
        self::assertStringContainsString('"dev"', $activation->reason);
    }

    #[Test]
    public function additionalSuffixesFromTheSiteSettingAreRefused(): void
    {
        $site = SiteFactory::site('https://shop-domain-staging.eu/', ['deniedHostSuffixes' => 'shop-domain-staging.eu']);

        self::assertFalse((new ActivationGate())->forHost($site, 'shop-domain-staging.eu')->active);
    }

    #[Test]
    public function aHostOutsideTheSiteIsRefused(): void
    {
        self::assertFalse((new ActivationGate())->forHost(SiteFactory::site(), 'other-domain.de')->active);
    }

    #[Test]
    public function eachHostGetsItsOwnDerivedKey(): void
    {
        $site = SiteFactory::site(extraLanguages: [
            ['languageId' => 2, 'title' => 'Schweiz', 'locale' => 'de_CH.UTF-8', 'base' => 'https://shop-domain.ch/'],
        ]);
        $gate = new ActivationGate();

        $de = $gate->forHost($site, 'shop-domain.de');
        $ch = $gate->forHost($site, 'shop-domain.ch');

        self::assertTrue($de->active);
        self::assertTrue($ch->active);
        self::assertNotNull($de->key);
        self::assertNotNull($ch->key);
        self::assertTrue($de->key->equals(Key::derive(self::ENCRYPTION_KEY, 'shop-domain.de')));
        self::assertFalse($de->key->equals($ch->key));
        self::assertSame('https://shop-domain.de/' . $de->key->value . '.txt', $de->keyFileUrl('shop-domain.de'));
        self::assertNull($de->keyLocation('shop-domain.de'));
    }

    #[Test]
    public function anExplicitKeyIsUsedForEveryHost(): void
    {
        $activation = (new ActivationGate())->forHost(SiteFactory::site(indexNowSettings: ['key' => 'my-registered-key-1']), 'shop-domain.de');

        self::assertSame('my-registered-key-1', $activation->key?->value);
    }

    #[Test]
    public function aMalformedExplicitKeyDeactivatesWithoutRevealingIt(): void
    {
        $activation = (new ActivationGate())->forHost(SiteFactory::site(indexNowSettings: ['key' => 'secret_with_underscore']), 'shop-domain.de');

        self::assertFalse($activation->active);
        self::assertStringNotContainsString('secret_with', $activation->reason);
    }

    #[Test]
    public function aSiteBelowAPathPublishesItsKeyThereAndSaysSo(): void
    {
        $activation = (new ActivationGate())->forHost(SiteFactory::site('https://shop-domain.de/shop'), 'shop-domain.de');

        self::assertSame('/shop/', $activation->basePath);
        self::assertSame('https://shop-domain.de/shop/' . $activation->key?->value . '.txt', $activation->keyLocation('shop-domain.de'));
    }

    #[Test]
    public function withoutAUsableEncryptionKeyNothingIsDerived(): void
    {
        $this->useEncryptionKey('short');

        self::assertFalse((new ActivationGate())->forHost(SiteFactory::site(), 'shop-domain.de')->active);
    }
}
