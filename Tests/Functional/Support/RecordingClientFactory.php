<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Tests\Functional\Support;

use Koh\IndexNow\HostPolicy;
use Koh\IndexNow\IndexNowClient;
use Koh\IndexNow\Typo3\Http\ClientFactory;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

final class RecordingClientFactory extends ClientFactory
{
    public function __construct(public readonly RecordingHttpClient $http) {}

    public function create(HostPolicy $policy): IndexNowClient
    {
        return new IndexNowClient($this->http, new RequestFactory(new GuzzleClientFactory()), new StreamFactory(), null, $policy);
    }
}
