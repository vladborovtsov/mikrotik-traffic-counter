<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Configuration;

final class RequestGuardService
{
    public function __construct(private readonly Configuration $config)
    {
    }

    public function isCollectorAuthorized(array $query, ?string $sourceIp): bool
    {
        return $this->isSourceIpAllowed($sourceIp) && $this->isAuthTokenValid($query);
    }

    public function isSourceIpAllowed(?string $sourceIp): bool
    {
        if (!$this->config->getBool('SOURCE_IP_ENABLED')) {
            return true;
        }

        if ($sourceIp === null || $sourceIp === '') {
            return false;
        }

        foreach ($this->config->getList('SOURCE_IP_ALLOWLIST') as $allowedEntry) {
            if ($this->matchesIpRule($sourceIp, $allowedEntry)) {
                return true;
            }
        }

        return false;
    }

    public function isAuthTokenValid(array $query): bool
    {
        if (!$this->config->getBool('AUTH_ENABLED')) {
            return true;
        }

        $providedToken = isset($query['auth']) ? trim((string) $query['auth']) : '';
        $expectedToken = $this->config->requireString('AUTH_TOKEN');

        return hash_equals($expectedToken, $providedToken);
    }

    private function matchesIpRule(string $sourceIp, string $allowedEntry): bool
    {
        if ($allowedEntry === $sourceIp) {
            return true;
        }

        if (!str_contains($allowedEntry, '/')) {
            return false;
        }

        [$network, $prefixLength] = explode('/', $allowedEntry, 2);

        if (!ctype_digit($prefixLength)) {
            return false;
        }

        $sourceBinary = @inet_pton($sourceIp);
        $networkBinary = @inet_pton($network);

        if ($sourceBinary === false || $networkBinary === false || strlen($sourceBinary) !== strlen($networkBinary)) {
            return false;
        }

        $prefix = (int) $prefixLength;
        $maxPrefix = strlen($networkBinary) * 8;

        if ($prefix < 0 || $prefix > $maxPrefix) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($sourceBinary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($sourceBinary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
    }
}
