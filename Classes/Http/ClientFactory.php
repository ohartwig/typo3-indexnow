<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Http;

use Koh\IndexNow\HostPolicy;
use Koh\IndexNow\IndexNowClient;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

class ClientFactory
{
    public function __construct(
        private readonly Typo3HttpClient $http,
        private readonly RequestFactory $requests,
        private readonly StreamFactory $streams,
    ) {}

    public function create(HostPolicy $policy): IndexNowClient
    {
        return new IndexNowClient($this->http, $this->requests, $this->streams, null, $policy);
    }
}
