<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\ConfigurationFactory;
use App\Tests\Support\CreatesTempDirectories;
use PHPUnit\Framework\TestCase;

final class ConfigurationFactoryTest extends TestCase
{
    use CreatesTempDirectories;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

    public function testRuntimeEnvironmentOverridesDotEnvValues(): void
    {
        $root = $this->createTempDirectory();

        file_put_contents($root . '/.env', implode(PHP_EOL, [
            'APP_TIMEZONE=Europe/Belgrade',
            'DB_DRIVER=sqlite',
            'DB_SQLITE_PATH=data/from-env-file.sqlite',
            'AUTH_ENABLED=false',
            '',
        ]));

        $configuration = ConfigurationFactory::create($root, [
            'APP_TIMEZONE' => 'UTC',
            'DB_DRIVER' => 'sqlite',
            'DB_SQLITE_PATH' => 'runtime.sqlite',
        ]);

        self::assertSame('UTC', $configuration->requireString('APP_TIMEZONE'));
        self::assertSame($root . '/runtime.sqlite', $configuration->requireString('DB_SQLITE_PATH'));
    }

    public function testEnabledAuthRequiresToken(): void
    {
        $root = $this->createTempDirectory();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AUTH_TOKEN is required');

        ConfigurationFactory::create($root, [
            'DB_DRIVER' => 'sqlite',
            'DB_SQLITE_PATH' => 'db.sqlite',
            'AUTH_ENABLED' => 'true',
        ]);
    }

    public function testInvalidTimezoneIsRejected(): void
    {
        $root = $this->createTempDirectory();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_TIMEZONE must be a valid timezone identifier.');

        ConfigurationFactory::create($root, [
            'APP_TIMEZONE' => 'Mars/Phobos',
            'DB_DRIVER' => 'sqlite',
            'DB_SQLITE_PATH' => 'db.sqlite',
        ]);
    }
}
