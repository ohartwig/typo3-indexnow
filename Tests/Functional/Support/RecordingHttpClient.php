<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Tests\Functional\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Response;

final class RecordingHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<int> $statuses answered in order; the last one repeats */
    public function __construct(private array $statuses = [200]) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $status = count($this->statuses) > 1 ? array_shift($this->statuses) : $this->statuses[0];

        return new Response('php://temp', $status);
    }

    /** @return array{host: string, key: string, keyLocation?: string, urlList: list<string>} */
    public function payload(int $index = 0): array
    {
        /** @var array{host: string, key: string, keyLocation?: string, urlList: list<string>} */
        return json_decode((string) $this->requests[$index]->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
