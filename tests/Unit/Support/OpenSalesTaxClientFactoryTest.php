<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Tests\Unit\Support;

use OpenSalesTax\OpenCart\Exceptions\ConfigurationException;
use OpenSalesTax\OpenCart\Support\ConfigBag;
use OpenSalesTax\OpenCart\Support\OpenSalesTaxClientFactory;
use OpenSalesTax\OpenCart\Support\UrlValidator;
use OpenSalesTax\OpenCart\Tests\Stubs\ArrayLogger;
use PHPUnit\Framework\TestCase;

final class OpenSalesTaxClientFactoryTest extends TestCase
{
    public function testEmptyBaseUrlReturnsNull(): void
    {
        $factory = new OpenSalesTaxClientFactory(new ArrayLogger());
        $bag = ConfigBag::fromArray(['status' => true, 'base_url' => '']);

        self::assertNull($factory->make($bag));
    }

    public function testValidPublicUrlReturnsClient(): void
    {
        $logger = new ArrayLogger();
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => ['8.8.8.8'],
        );
        $factory = new OpenSalesTaxClientFactory($logger, $validator);

        $bag = ConfigBag::fromArray([
            'status' => true,
            'base_url' => 'https://ost.example.com',
        ]);

        self::assertNotNull($factory->make($bag));
        self::assertSame(0, $logger->countAtLevel('warning'));
    }

    public function testPrivateUrlWithFailSoftLogsAndReturnsNull(): void
    {
        $logger = new ArrayLogger();
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => [$host],
        );
        $factory = new OpenSalesTaxClientFactory($logger, $validator);

        $bag = ConfigBag::fromArray([
            'status' => true,
            'base_url' => 'http://10.0.0.1:8080',
            'fail_hard' => false,
        ]);

        self::assertNull($factory->make($bag));
        self::assertSame(1, $logger->countAtLevel('warning'));
    }

    public function testPrivateUrlWithFailHardThrowsConfigurationException(): void
    {
        $logger = new ArrayLogger();
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => [$host],
        );
        $factory = new OpenSalesTaxClientFactory($logger, $validator);

        $bag = ConfigBag::fromArray([
            'status' => true,
            'base_url' => 'http://10.0.0.1:8080',
            'fail_hard' => true,
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/private/');
        $factory->make($bag);
    }

    public function testAllowPrivateNetsLetsPrivateUrlThrough(): void
    {
        $logger = new ArrayLogger();
        $validator = new UrlValidator(
            allowPrivateNets: true,
            hostResolver: static fn (string $host): array => [$host],
        );
        $factory = new OpenSalesTaxClientFactory($logger, $validator);

        $bag = ConfigBag::fromArray([
            'status' => true,
            'base_url' => 'http://10.0.0.1:8080',
            'allow_private_nets' => true,
        ]);

        self::assertNotNull($factory->make($bag));
    }

    public function testEmptyApiKeyPassesNullToClient(): void
    {
        // We can't introspect the built Guzzle client directly, but we can at
        // least confirm `make` succeeds without an api_key set.
        $logger = new ArrayLogger();
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => ['8.8.8.8'],
        );
        $factory = new OpenSalesTaxClientFactory($logger, $validator);

        $bag = ConfigBag::fromArray([
            'status' => true,
            'base_url' => 'https://ost.example.com',
            'api_key' => '',
        ]);

        self::assertNotNull($factory->make($bag));
    }

    public function testResolveDirectiveDefaultsHttpsPort443(): void
    {
        self::assertSame(
            'ost.example.com:443:203.0.113.42',
            OpenSalesTaxClientFactory::buildResolveDirective(
                'https://ost.example.com',
                'ost.example.com',
                '203.0.113.42',
            ),
        );
    }

    public function testResolveDirectiveDefaultsHttpPort80(): void
    {
        self::assertSame(
            'ost.example.com:80:203.0.113.42',
            OpenSalesTaxClientFactory::buildResolveDirective(
                'http://ost.example.com',
                'ost.example.com',
                '203.0.113.42',
            ),
        );
    }

    public function testResolveDirectiveExplicitPortWins(): void
    {
        self::assertSame(
            'lan-engine.local:8080:10.0.0.5',
            OpenSalesTaxClientFactory::buildResolveDirective(
                'http://lan-engine.local:8080',
                'lan-engine.local',
                '10.0.0.5',
            ),
        );
    }

    public function testRejectedUrlIsCaughtAndWrappedAsWarning(): void
    {
        // Force rejection via a resolver returning a private IP — exercises the
        // catch + log path without needing to subclass UrlValidator.
        $logger = new ArrayLogger();
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => ['10.0.0.1'],
        );
        $factory = new OpenSalesTaxClientFactory($logger, $validator);
        $bag = ConfigBag::fromArray([
            'status'   => true,
            'base_url' => 'https://anything.example',
            'fail_hard' => false,
        ]);

        self::assertNull($factory->make($bag));
        self::assertSame(1, $logger->countAtLevel('warning'));
        $reason = $logger->records[0]['context']['reason'] ?? '';
        self::assertIsString($reason);
        self::assertStringContainsString('private', $reason);
    }
}
