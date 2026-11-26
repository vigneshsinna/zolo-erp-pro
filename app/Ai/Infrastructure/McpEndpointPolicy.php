<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

/** Reject private destinations and pin the checked DNS address for the TLS connection. */
final class McpEndpointPolicy
{
    public function __construct(private readonly McpPublicAddressResolver $resolver) {}

    public function connection(string $url, array $allowedHosts): array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || $host === ''
            || !in_array($host, $allowedHosts, true) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443
            || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)
            || !preg_match('~^/[A-Za-z0-9_./-]*$~D', $parts['path'] ?? '/')
            || preg_match('~(?:^|/)\.\.(?:/|$)|//~', $parts['path'] ?? '/')) {
            throw new InvalidArgumentException('An approved HTTPS MCP endpoint is required.');
        }
        $addresses = $this->resolver->addresses($host);
        if ($addresses === [] || count($addresses) > 32) {
            throw new InvalidArgumentException('The approved MCP host has no bounded public address set.');
        }
        foreach ($addresses as $address) {
            if (!$this->isPublic($address)) throw new InvalidArgumentException('Private or reserved MCP destinations are prohibited.');
        }
        sort($addresses, SORT_STRING);
        $address = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
        if (!extension_loaded('curl')) throw new \LogicException('Outbound MCP requires the cURL transport.');
        return ['allow_redirects' => false, 'proxy' => '', 'verify' => true, 'stream' => true,
            'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$address], CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS]];
    }

    private function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
        if (str_contains($address, ':')) {
            return IpUtils::checkIp($address, '2000::/3')
                && !IpUtils::checkIp($address, ['2001:db8::/32', '2001::/32', '2002::/16']);
        }
        return !IpUtils::checkIp($address, ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
            '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24',
            '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4']);
    }
}
