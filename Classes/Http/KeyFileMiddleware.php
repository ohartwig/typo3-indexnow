<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Http;

use Koh\IndexNow\HostName;
use Koh\IndexNow\Typo3\Activation\ActivationGate;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Serves "<key>.txt" — the file a search engine fetches to confirm the key.
 *
 * There is no file on disk: the key is derived per host, so writing files
 * would mean one per host, per replica, kept in sync on every key change.
 *
 * Anything that is not exactly this host's key file, and every request on a
 * host that does not announce, falls through to TYPO3 untouched and gets the
 * site's regular 404. A development host therefore has no key file at all,
 * and the response does not tell a prober whether a guess was close.
 */
final readonly class KeyFileMiddleware implements MiddlewareInterface
{
    /** Cheap pre-filter so that only plausible key-file requests reach the gate. */
    private const CANDIDATE = '#/[A-Za-z0-9-]{8,128}\.txt$#D';

    public function __construct(
        private ActivationGate $gate,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        if (($method !== 'GET' && $method !== 'HEAD') || preg_match(self::CANDIDATE, $path) !== 1) {
            return $handler->handle($request);
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $handler->handle($request);
        }

        $activation = $this->gate->forHost($site, HostName::normalize($request->getUri()->getHost()));
        if (!$activation->active || $activation->key === null || !$activation->key->isFilePath($path, $activation->basePath)) {
            return $handler->handle($request);
        }

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withHeader('X-Robots-Tag', 'noindex')
            ->withBody($this->streams->createStream($method === 'HEAD' ? '' : $activation->key->value));
    }
}
