<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Infrastructure;

use App\Ai\Infrastructure\McpEndpointPolicy;
use App\Ai\Infrastructure\McpPublicAddressResolver;
use Tests\TestCase;

final class McpEndpointPolicyTest extends TestCase
{
    public function test_https_host_port_and_url_components_are_closed(): void
    {
        $resolver = $this->mock(McpPublicAddressResolver::class, fn ($mock) => $mock->shouldReceive('addresses')->never());
        foreach (['http://mcp.vendor.example/mcp', 'https://127.0.0.1/mcp', 'https://mcp.vendor.example:8443/mcp',
            'https://other.example/mcp', 'https://user:secret@mcp.vendor.example/mcp', 'https://mcp.vendor.example/mcp?token=secret',
            'https://mcp.vendor.example/mcp#fragment', 'https://mcp.vendor.example/../mcp'] as $url) {
            try {
                (new McpEndpointPolicy($resolver))->connection($url, ['mcp.vendor.example']);
                $this->fail('Unapproved URL must fail.');
            } catch (\InvalidArgumentException) { $this->assertTrue(true); }
        }
    }

    public function test_private_reserved_and_mixed_dns_answers_are_rejected(): void
    {
        foreach ([[], ['127.0.0.1'], ['10.1.1.1'], ['100.64.1.1'], ['169.254.169.254'], ['192.168.1.1'],
            ['::1'], ['::ffff:127.0.0.1'], ['fe80::1'], ['2001:db8::1'], ['64:ff9b::a00:1'], ['8.8.8.8', '10.1.1.1']] as $addresses) {
            $resolver = $this->mock(McpPublicAddressResolver::class, fn ($mock) => $mock->shouldReceive('addresses')->once()->andReturn($addresses));
            try {
                (new McpEndpointPolicy($resolver))->connection('https://mcp.vendor.example/mcp', ['mcp.vendor.example']);
                $this->fail('Private or reserved DNS result must fail.');
            } catch (\InvalidArgumentException) { $this->assertTrue(true); }
        }
    }
}
