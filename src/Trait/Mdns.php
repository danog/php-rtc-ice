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

use RuntimeException;
use Throwable;
use Webrtc\MDNS\Factory;
use Webrtc\MDNS\MulticastExecutor;

/**
 * mDNS (Multicast DNS) Resolution Trait
 *
 * Provides functionality for resolving .local domains using mDNS (Multicast DNS) protocol.
 * This trait is specifically designed for local network service discovery and resolution.
 *
 * Key Features:
 * - Resolves .local domains to IPv4 addresses (A records)
 * - Validates whether a domain is an mDNS-compatible .local domain
 * - Integrated error handling with optional logging
 * - Resolution blocks the calling fiber rather than returning a promise
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6762 RFC 6762 for mDNS specifications
 */
trait Mdns
{
    /** @var string|null Override for the mDNS nameserver (tests bind a loopback responder). */
    private ?string $mdnsNameserver = null;

    /**
     * Point mDNS resolution at a specific nameserver.
     *
     * Production uses 224.0.0.251:5353. Tests bind a unicast loopback responder
     * because Windows cannot bind a datagram socket to the multicast group
     * (WSAEADDRNOTAVAIL) and many hosts do not deliver group traffic locally.
     */
    public function setMdnsNameserver(?string $nameserver): void
    {
        $this->mdnsNameserver = $nameserver;
    }

    /**
     * Resolve an mDNS .local domain to its IPv4 address
     *
     * Performs an mDNS query (type A record) for the given .local domain and returns
     * the first resolved IPv4 address. The resolution is performed asynchronously.
     *
     * @param string $domain The .local domain to resolve (e.g., "myservice.local")
     *
     * @return string|false Returns the IPv4 address as string if resolved successfully,
     *                      false if resolution fails or domain is invalid
     *
     * @throws RuntimeException If the mDNS resolver encounters an unexpected error
     *
     * @example
     * $ip = $this->resolveMdns('printer.local');
     * // Returns "192.168.1.100" or false if resolution fails
     */
    private function resolveMdns(string $domain): string|false
    {
        $executor = $this->mdnsNameserver !== null
            ? new MulticastExecutor($this->mdnsNameserver)
            : null;
        $factory = new Factory($executor);
        $resolver = $factory->createResolver();

        try {
            // IP successfully resolved
            return $resolver->resolve($domain);
        } catch (Throwable $e) {
            $this?->logger?->error(sprintf("Could not resolve the domain: %s. %s", $domain, $e->getMessage()));
            return false;
        }
    }

    /**
     * Validate whether a domain is a valid mDNS .local domain
     *
     * Checks if the given domain conforms to the mDNS specification for .local domains:
     * - Ends with ".local"
     * - Contains only a-z, A-Z, 0-9, and hyphens
     * - Each label part is 1-63 characters long
     *
     * @param string $domain Domain to validate (e.g., "my-device.local")
     *
     * @return bool True if the domain is a valid mDNS domain, false otherwise
     *
     * @example
     * $valid = $this->isMdnsDomain('mydevice.local'); // true
     * $valid = $this->isMdnsDomain('example.com'); // false
     * $valid = $this->isMdnsDomain('invalid..local'); // false
     */
    private function isMdnsDomain(string $domain): bool
    {
        return preg_match("/^[a-zA-Z0-9-]{1,63}\.local$/", $domain) === 1;
    }
}