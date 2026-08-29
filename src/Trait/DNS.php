<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\ICE\Trait;

use Amp\Dns\DnsRecord;
use Throwable;
use Webrtc\Exception\InvalidArgumentException;
use function Amp\Dns\resolve;

/**
 * DNS Resolution Trait
 *
 * Provides DNS resolution functionality for ICE (Interactive Connectivity Establishment) implementations.
 * This trait handles both direct IP addresses and domain name resolution with caching support.
 *
 * Features:
 * - Automatic fallback to Google's public DNS (8.8.8.8) if no system nameservers are configured
 * - DNS resolution through amphp
 * - Caching of DNS queries for improved performance
 * - Support for both IPv4 and IPv6 addresses
 *
 */
trait DNS
{
    /**
     * Resolve a network address to its IP equivalent
     *
     * This method takes an address array (typically [host, port]) and resolves the host
     * to an IP address if it isn't already one, through the system resolver, which already
     * handles caching and fallback.
     *
     * The lookup blocks the calling fiber rather than returning a promise.
     *
     * @param array $address An array containing [host, port] where host can be either:
     *                       - A domain name (e.g., 'example.com')
     *                       - An IP address (IPv4 or IPv6)
     *
     * @return array{0: string, 1: int<0, 65535>} Returns an array in the format [resolved_ip, original_port]
     *
     * @throws Throwable If DNS resolution fails or encounters an error
     *
     * @example
     * // Resolve a domain name
     * $resolved = $this->resolveDNS(['example.com', 1234]);
     * // Might return ['93.184.216.34', 1234]
     *
     * // Pass through an existing IP
     * $resolved = $this->resolveDNS(['192.0.2.1', 5678]);
     * // Returns ['192.0.2.1', 5678]
     */
    private function resolveDNS(array $address): array
    {
        $host = $address[0] ?? null;
        $port = $address[1] ?? null;

        if (!is_string($host) || $host === '') {
            throw new InvalidArgumentException("Invalid STUN/DNS host given: " . (is_scalar($host) ? (string)$host : gettype($host)));
        }

        if (!is_int($port) || $port < 0 || $port > 65535) {
            throw new InvalidArgumentException("Invalid STUN/DNS port given: " . (is_scalar($port) ? (string)$port : gettype($port)));
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host, $port];
        }

        // The system resolver already caches and falls back, so there is nothing to configure
        // here; the first A or AAAA record is what the candidate needs.
        /** @var list<DnsRecord> $records */
        $records = resolve($host);

        if (!isset($records[0])) {
            throw new \RuntimeException("No DNS records found for {$host}");
        }

        return [$records[0]->getValue(), $port];
    }
}