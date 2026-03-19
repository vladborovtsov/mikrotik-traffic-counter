<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\ConfigurationFactory;
use App\Services\RequestGuardService;
use App\Tests\Support\CreatesTempDirectories;
use PHPUnit\Framework\TestCase;

final class RequestGuardServiceTest extends TestCase
{
    use CreatesTempDirectories;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

    public function testGuardAllowsOpenCollectorWhenRestrictionsAreDisabled(): void
    {
        $configuration = ConfigurationFactory::create($this->createTempDirectory(), [
            'DB_DRIVER' => 'sqlite',
            'DB_SQLITE_PATH' => 'db.sqlite',
            'AUTH_ENABLED' => 'false',
            'SOURCE_IP_ENABLED' => 'false',
        ]);

        $guard = new RequestGuardService($configuration);

        self::assertTrue($guard->isCollectorAuthorized([], null));
    }

    public function testGuardRejectsIncorrectTokenAndUnexpectedIp(): void
    {
        $configuration = ConfigurationFactory::create($this->createTempDirectory(), [
            'DB_DRIVER' => 'sqlite',
            'DB_SQLITE_PATH' => 'db.sqlite',
            'AUTH_ENABLED' => 'true',
            'AUTH_TOKEN' => 'secret',
            'SOURCE_IP_ENABLED' => 'true',
            'SOURCE_IP_ALLOWLIST' => '10.0.0.1,10.0.0.2',
        ]);

        $guard = new RequestGuardService($configuration);

        self::assertFalse($guard->isCollectorAuthorized(['auth' => 'wrong'], '10.0.0.1'));
        self::assertFalse($guard->isCollectorAuthorized(['auth' => 'secret'], '127.0.0.1'));
        self::assertTrue($guard->isCollectorAuthorized(['auth' => 'secret'], '10.0.0.2'));
    }

    public function testGuardSupportsCidrSourceIpEntries(): void
    {
        $configuration = ConfigurationFactory::create($this->createTempDirectory(), [
            'DB_DRIVER' => 'sqlite',
            'DB_SQLITE_PATH' => 'db.sqlite',
            'SOURCE_IP_ENABLED' => 'true',
            'SOURCE_IP_ALLOWLIST' => '10.10.0.0/16,2001:db8::/32',
        ]);

        $guard = new RequestGuardService($configuration);

        self::assertTrue($guard->isSourceIpAllowed('10.10.5.20'));
        self::assertTrue($guard->isSourceIpAllowed('2001:db8::1234'));
        self::assertFalse($guard->isSourceIpAllowed('10.11.0.1'));
        self::assertFalse($guard->isSourceIpAllowed('2001:db9::1'));
    }
}
