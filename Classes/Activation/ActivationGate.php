<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Activation;

use Koh\IndexNow\Exception\InvalidKey;
use Koh\IndexNow\HostName;
use Koh\IndexNow\HostPolicy;
use Koh\IndexNow\Key;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The single place that decides whether a host of a site is announced.
 *
 * Every condition must hold, and the order is the order of the reasons a
 * person needs to read: set not included, switched off, not Production, host
 * not part of the site, host refused by the policy, unusable key. The
 * Production check is not configurable on purpose — "keine Dev-Domains" is a
 * property of the software, not of a setting someone can flip on a staging
 * system that happens to have a public name.
 */
final class ActivationGate
{
    /** @var array<string, Activation> */
    private array $cache = [];

    public function forHost(Site $site, string $host): Activation
    {
        $host = HostName::normalize($host);
        $cacheKey = spl_object_id($site) . '|' . $site->getIdentifier() . '|' . $host;

        return $this->cache[$cacheKey] ??= $this->evaluate($site, $host);
    }

    /**
     * Every host the site answers on, with the shortest base path per host.
     *
     * @return array<string, array{path: string, scheme: string}>
     */
    public function hostsOf(Site $site): array
    {
        $bases = [$site->getBase()];
        foreach ($site->getLanguages() as $language) {
            $bases[] = $language->getBase();
        }

        $hosts = [];
        foreach ($bases as $base) {
            $host = HostName::normalize($base->getHost());
            if ($host === '') {
                continue;
            }
            // "/shop" and "/shop/" both put the site below /shop/.
            $path = '/' . trim($base->getPath(), '/');
            $path = $path === '/' ? '/' : $path . '/';
            if (!isset($hosts[$host]) || strlen($path) < strlen($hosts[$host]['path'])) {
                $hosts[$host] = ['path' => $path, 'scheme' => $base->getScheme() !== '' ? $base->getScheme() : 'https'];
            }
        }

        return $hosts;
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    private function evaluate(Site $site, string $host): Activation
    {
        if (!SiteSettings::includesSet($site)) {
            return Activation::inactive('the site does not include the koh/indexnow set');
        }
        if (!SiteSettings::enabled($site)) {
            return Activation::inactive('switched off by the site setting indexnow.enabled');
        }
        $context = Environment::getContext();
        if (!$context->isProduction()) {
            return Activation::inactive(sprintf('the application context is "%s"; only Production announces', (string) $context));
        }

        $hosts = $this->hostsOf($site);
        if (!isset($hosts[$host])) {
            return Activation::inactive(sprintf('"%s" is not a host of site "%s"', $host, $site->getIdentifier()));
        }

        $policy = HostPolicy::default()->withDeniedSuffixes(...SiteSettings::deniedHostSuffixes($site));
        $refusal = $policy->refusalReason($host);
        if ($refusal !== null) {
            return Activation::inactive($refusal);
        }

        try {
            $key = $this->keyFor($site, $host);
        } catch (InvalidKey $e) {
            return Activation::inactive($e->getMessage());
        }

        return Activation::active($key, $hosts[$host]['path'], $hosts[$host]['scheme'], $policy);
    }

    /** @throws InvalidKey */
    private function keyFor(Site $site, string $host): Key
    {
        $explicit = SiteSettings::explicitKey($site);
        if ($explicit !== '') {
            return Key::fromString($explicit);
        }

        return Key::derive(self::encryptionKey(), $host);
    }

    private static function encryptionKey(): string
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $system = is_array($configuration) ? ($configuration['SYS'] ?? null) : null;
        $secret = is_array($system) ? ($system['encryptionKey'] ?? null) : null;

        return is_string($secret) ? $secret : '';
    }
}
