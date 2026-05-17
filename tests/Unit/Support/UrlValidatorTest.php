<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Tests\Unit\Support;

use InvalidArgumentException;
use OpenSalesTax\OpenCart\Support\UrlValidator;
use PHPUnit\Framework\TestCase;

final class UrlValidatorTest extends TestCase
{
    public function testEmptyUrlIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/empty/');
        (new UrlValidator(false))->validate('');
    }

    public function testMalformedUrlIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/fully-qualified/');
        (new UrlValidator(false))->validate('not a url');
    }

    public function testMissingSchemeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UrlValidator(false))->validate('ost.example.com');
    }

    public function testFtpSchemeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/http or https/');
        (new UrlValidator(false))->validate('ftp://ost.example.com');
    }

    public function testFileSchemeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UrlValidator(false))->validate('file:///etc/passwd');
    }

    public function testGopherSchemeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UrlValidator(false))->validate('gopher://ost.example.com');
    }

    public function testPublicUrlIsAccepted(): void
    {
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => ['8.8.8.8'],
        );

        $validator->validate('https://ost.example.com');
        self::assertTrue(true); // reached only if validate didn't throw
    }

    public function testRfc1918TenDotIsRejectedByDefault(): void
    {
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => [$host],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/private/');
        $validator->validate('http://10.0.0.1:8080');
    }

    public function testRfc1918OneNineTwoDotIsRejectedByDefault(): void
    {
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => [$host],
        );

        $this->expectException(InvalidArgumentException::class);
        $validator->validate('http://192.168.1.100:8080');
    }

    public function testRfc1918OneSevenTwoDotIsRejectedByDefault(): void
    {
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => [$host],
        );

        $this->expectException(InvalidArgumentException::class);
        $validator->validate('http://172.16.5.5:8080');
    }

    public function testLoopbackIsRejectedByDefault(): void
    {
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => $host === 'localhost' ? ['127.0.0.1'] : [$host],
        );

        $this->expectException(InvalidArgumentException::class);
        $validator->validate('http://localhost:8080');
    }

    public function testLinkLocalIpIsRejectedByDefault(): void
    {
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => ['169.254.169.254'],
        );

        $this->expectException(InvalidArgumentException::class);
        $validator->validate('http://metadata.example/');
    }

    public function testCgnatIsRejectedByDefault(): void
    {
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => ['100.64.0.1'],
        );

        $this->expectException(InvalidArgumentException::class);
        $validator->validate('http://cgnat.example/');
    }

    public function testMulticastIsRejectedByDefault(): void
    {
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => ['224.0.0.1'],
        );

        $this->expectException(InvalidArgumentException::class);
        $validator->validate('http://multicast.example/');
    }

    public function testUnresolvableHostIsRejected(): void
    {
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => [],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/could not be resolved/');
        $validator->validate('https://no-such-host.invalid');
    }

    public function testAllowPrivateNetsBypassesPrivateChecks(): void
    {
        $validator = new UrlValidator(
            allowPrivateNets: true,
            hostResolver: static fn (string $host): array => [$host],
        );

        $validator->validate('http://10.0.0.1:8080');
        $validator->validate('http://127.0.0.1:8080');
        self::assertTrue(true); // no exception
    }

    public function testAllowPrivateNetsStillRejectsNonHttpScheme(): void
    {
        $validator = new UrlValidator(
            allowPrivateNets: true,
            hostResolver: static fn (string $host): array => [$host],
        );

        $this->expectException(InvalidArgumentException::class);
        $validator->validate('ftp://10.0.0.1/');
    }

    public function testMultipleResolvedIpsAreAllChecked(): void
    {
        // One public, one RFC1918 â€” should still reject.
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => ['8.8.8.8', '10.0.0.1'],
        );

        $this->expectException(InvalidArgumentException::class);
        $validator->validate('https://mixed.example/');
    }

    public function testDefaultResolverHandlesLiteralIpWithoutDns(): void
    {
        // No resolver injected â€” uses the default. Literal IP should short-circuit
        // gethostbynamel and be rejected as private.
        $validator = new UrlValidator(allowPrivateNets: false);

        $this->expectException(InvalidArgumentException::class);
        $validator->validate('http://10.20.30.40:8080');
    }

    public function testValidateAndResolveReturnsHostnameAndIpForHostname(): void
    {
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => ['203.0.113.42'],
        );

        [$host, $ip] = $validator->validateAndResolve('https://ost.example.com');
        self::assertSame('ost.example.com', $host);
        self::assertSame('203.0.113.42', $ip);
    }

    public function testValidateAndResolveReturnsFirstPublicIpFromList(): void
    {
        $validator = new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => ['198.51.100.7', '203.0.113.42'],
        );

        [, $ip] = $validator->validateAndResolve('https://ost.example.com');
        self::assertSame('198.51.100.7', $ip);
    }

    public function testValidateAndResolveOnLiteralIpReturnsIpItself(): void
    {
        // Default resolver short-circuits literal IPs to [$host], so we keep
        // the input IP as the pinned value.
        $validator = new UrlValidator(allowPrivateNets: false);
        [, $ip] = $validator->validateAndResolve('https://203.0.113.42');
        self::assertSame('203.0.113.42', $ip);
    }

    public function testValidateAndResolveOnPrivateNetsReturnsResolvedIp(): void
    {
        $validator = new UrlValidator(
            allowPrivateNets: true,
            hostResolver: static fn (string $host): array => ['10.0.0.5'],
        );

        [$host, $ip] = $validator->validateAndResolve('http://lan-engine.local:8080');
        self::assertSame('lan-engine.local', $host);
        self::assertSame('10.0.0.5', $ip);
    }
}
