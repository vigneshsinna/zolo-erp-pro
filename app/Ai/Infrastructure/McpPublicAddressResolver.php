<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure;

/** DNS is isolated so tests never perform a real external lookup. */
class McpPublicAddressResolver
{
    public function addresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) return [];
        return array_values(array_unique(array_filter(array_map(
            static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        ))));
    }
}
