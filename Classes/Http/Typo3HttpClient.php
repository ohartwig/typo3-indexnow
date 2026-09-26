<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

/**
 * PSR-18 on top of TYPO3's configured HTTP client, with the limits a
 * background job needs.
 *
 * TYPO3's HTTP settings (proxy, CA bundle, allowed_hosts under the context
 * "indexnow") still apply. What this adds is per request: TYPO3's default
 * timeout is 0 — wait forever — and a queue worker that hangs on one slow
 * engine blocks every site behind it. Redirects are not followed because the
 * body carries the key.
 */
final readonly class Typo3HttpClient implements ClientInterface
{
    public const TIMEOUT = 10;
    public const CONNECT_TIMEOUT = 5;

    public function __construct(private GuzzleClientFactory $factory) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->factory->getClient('indexnow')->send($request, [
            'timeout' => self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }
}
