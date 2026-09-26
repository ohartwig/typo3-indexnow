<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Typo3\Tests\Unit\Http;

use Koh\IndexNow\Key;
use Koh\IndexNow\Typo3\Activation\ActivationGate;
use Koh\IndexNow\Typo3\Http\KeyFileMiddleware;
use Koh\IndexNow\Typo3\Tests\Unit\ProductionContext;
use Koh\IndexNow\Typo3\Tests\Unit\SiteFactory;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class KeyFileMiddlewareTest extends UnitTestCase
{
    use ProductionContext;

    protected bool $backupEnvironment = true;

    private const ENCRYPTION_KEY = 'f00dfeedf00dfeedf00dfeedf00dfeedf00dfeedf00dfeedf00dfeedf00dfeed';

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useEncryptionKey(self::ENCRYPTION_KEY);
        $this->useContext('Production');
        $this->key = Key::derive(self::ENCRYPTION_KEY, 'shop-domain.de')->value;
    }

    #[Test]
    public function theKeyFileIsServedAsPlainText(): void
    {
        $response = $this->process('GET', 'https://shop-domain.de/' . $this->key . '.txt');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
        self::assertSame($this->key, (string) $response->getBody());
    }

    #[Test]
    public function headGetsHeadersOnly(): void
    {
        $response = $this->process('HEAD', 'https://shop-domain.de/' . $this->key . '.txt');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function everythingElseReachesTypo3(): void
    {
        $other = Key::derive(self::ENCRYPTION_KEY, 'shop-domain.ch')->value;
        foreach ([
            ['GET', 'https://shop-domain.de/' . $other . '.txt'],
            ['GET', 'https://shop-domain.de/en/' . $this->key . '.txt'],
            ['GET', 'https://shop-domain.de/' . substr($this->key, 0, -1) . '.txt'],
            ['POST', 'https://shop-domain.de/' . $this->key . '.txt'],
            ['GET', 'https://shop-domain.de/robots.txt'],
        ] as [$method, $url]) {
            self::assertSame(404, $this->process($method, $url)->getStatusCode(), $method . ' ' . $url);
        }
    }

    #[Test]
    public function aNonProductionSystemHasNoKeyFile(): void
    {
        $this->useContext('Development');

        self::assertSame(404, $this->process('GET', 'https://shop-domain.de/' . $this->key . '.txt')->getStatusCode());
    }

    #[Test]
    public function aDevelopmentHostHasNoKeyFile(): void
    {
        $key = Key::derive(self::ENCRYPTION_KEY, 'shop.dev.shop-domain.de')->value;
        $site = SiteFactory::site('https://shop.dev.shop-domain.de/');

        self::assertSame(404, $this->process('GET', 'https://shop.dev.shop-domain.de/' . $key . '.txt', $site)->getStatusCode());
    }

    private function process(string $method, string $url, ?Site $site = null): ResponseInterface
    {
        $request = (new ServerRequest($url, $method))->withAttribute('site', $site ?? SiteFactory::site());
        $middleware = new KeyFileMiddleware(new ActivationGate(), new ResponseFactory(), new StreamFactory());

        return $middleware->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(404);
            }
        });
    }
}
