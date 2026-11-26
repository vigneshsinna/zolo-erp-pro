<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure;

use App\Ai\DTOs\AiToolRegistration;

final class OutboundMcpReadCatalog
{
    /** @return list<AiToolRegistration> */
    public static function registrations(): array
    {
        if (!config('ai_infrastructure.mcp.outbound.enabled', false)) return [];
        $registrations = [];
        foreach (config('ai_infrastructure.mcp.outbound.servers', []) as $server => $definition) {
            if (!is_string($server) || !preg_match('/^[a-z][a-z0-9_]{0,29}$/D', $server)) continue;
            foreach ($definition['tools'] ?? [] as $tool => $config) {
                if (!is_string($tool) || !preg_match('/^[a-z][a-z0-9_]{0,29}$/D', $tool)
                    || ($config['read_only'] ?? false) !== true || !isset($config['provider_alias'])
                    || !in_array('ai.use', $config['required_permissions'] ?? [], true)
                    || count($config['required_permissions']) < 2) continue;
                $handler = new ConfiguredMcpReadHandler($server, $tool, $config);
                $registrations[] = new AiToolRegistration($handler, $config['provider_alias'],
                    array_values(array_diff($config['required_permissions'], ['ai.use'])));
            }
        }
        return $registrations;
    }
}
