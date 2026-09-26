<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Activation;

use Koh\IndexNow\HostPolicy;
use Koh\IndexNow\Key;

/**
 * Whether one host of one site announces changes, and with which key.
 *
 * Inactive always carries a reason: "why is nothing submitted?" is the first
 * question anyone asks, and indexnow:status answers it from here.
 */
final readonly class Activation
{
    private function __construct(
        public bool $active,
        public string $reason,
        public ?Key $key,
        public string $basePath,
        public string $scheme,
        public ?HostPolicy $hostPolicy,
    ) {}

    public static function active(Key $key, string $basePath, string $scheme, HostPolicy $hostPolicy): self
    {
        return new self(true, '', $key, $basePath, $scheme, $hostPolicy);
    }

    public static function inactive(string $reason): self
    {
        return new self(false, $reason, null, '/', 'https', null);
    }

    public function keyFileUrl(string $host): ?string
    {
        return $this->key === null ? null : $this->scheme . '://' . $host . $this->key->filePath($this->basePath);
    }

    /** The protocol's keyLocation — only needed when the key file is not at the host root. */
    public function keyLocation(string $host): ?string
    {
        return $this->basePath === '/' ? null : $this->keyFileUrl($host);
    }
}
