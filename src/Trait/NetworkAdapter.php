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

use Webrtc\ICE\Utils;

/**
 * Network Adapter Trait
 *
 * Provides functionality for retrieving and managing local network interface addresses.
 * This trait handles both IPv4 and IPv6 addresses with caching support and filtering capabilities.
 *
 * Key Features:
 * - Retrieves all available network interface addresses
 * - Filters loopback addresses (127.0.0.1 and ::1)
 * - Supports both IPv4 and IPv6 addresses
 * - Includes address caching mechanism
 * - Provides filtered results based on protocol preferences
 */
trait NetworkAdapter
{
    /** @var array<string, array<int, string>>|null */
    private ?array $hostAddressCache = null;
    /**
     * Retrieve host addresses from local network interfaces
     *
     * Gets all available host addresses from network adapters, with optional protocol filtering.
     * Results are cached to improve performance on subsequent calls.
     *
     * @param bool $useIPv4 Whether to include IPv4 addresses (default: true)
     * @param bool $useIPv6 Whether to include IPv6 addresses (default: true)
     * @return array<int, string> Array of filtered IP addresses based on protocol preferences
     * @throws \Throwable If network interface retrieval fails
     *
     * @example
     * // Get all addresses
     * $addresses = $this->getHostAddresses(true, true);
     *
     * // Get only IPv6 addresses
     * $addresses = $this->getHostAddresses(false, true);
     */
    public function getHostAddresses(bool $useIPv4, bool $useIPv6): array
    {
        $hostAddresses = $this->hostAddressCache ?? $this->getHostFromNetworkAdapter();

        return $this->filterAddresses($hostAddresses, $useIPv4, $useIPv6);
    }

    /**
     * Retrieve and process addresses from network interfaces
     *
     * Collects all unicast addresses from available network interfaces,
     * excluding loopback addresses, and organizes them by IP version.
     * IPv6 addresses are enclosed in square brackets for URI compatibility.
     *
     * @return array<string, array<int, string>> Array of addresses grouped by IP version (v4, v6)
     *
     * @example
     * $addresses = $this->getHostFromNetworkAdapter();
     * // Returns ['v4' => ['192.168.1.2'], 'v6' => ['[fe80::1]']]
     */
    private function getHostFromNetworkAdapter(): array
    {
        /** @var array<string, array<int, string>> $hostAddresses */
        $hostAddresses = ['v4' => [], 'v6' => []];

        /** @var array<string, array{unicast?: array<int, array{address?: string}>}> $interfaces */
        $interfaces = $this->getInterfaces();

        foreach ($interfaces as $interface) {
            foreach ($interface["unicast"] ?? [] as $adapter) {
                $address = $adapter["address"] ?? null;
                if (!is_string($address)) {
                    continue;
                }

                if ($this->isUnusableHostAddress($address)) {
                    continue;
                }

                $version = Utils::IPVersion($address);
                if ($version === false) {
                    continue;
                }

                $hostAddresses[$version === 6 ? "v6" : "v4"][] = $version === 6 ? "[$address]" : $address;
            }
        }

        $this->hostAddressCache = $hostAddresses;
        return $hostAddresses;
    }

    /**
     * Addresses that cannot form a working ICE pair on this host.
     *
     * Loopback is never useful to a remote peer. IPv6 link-local (fe80::/10)
     * needs a zone index that ICE SDP does not carry; macOS enumerates many
     * such interfaces (awdl, llw, utun) and they would otherwise be checked
     * first. IPv4 link-local (169.254/16) is the same class of address.
     */
    private function isUnusableHostAddress(string $address): bool
    {
        if (in_array($address, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        if (str_starts_with($address, '169.254.')) {
            return true;
        }

        return str_starts_with(strtolower($address), 'fe80:');
    }

    /**
     * Filter addresses by protocol version
     *
     * Filters the collected addresses based on the requested protocol versions.
     * Can return IPv4 only, IPv6 only, or combined addresses.
     *
     * @param array $hostAddresses Addresses to filter (grouped by v4/v6)
     * @param bool $useIPv4 Whether to include IPv4 addresses
     * @param bool $useIPv6 Whether to include IPv6 addresses
     * @return array<int, string> Filtered array of addresses
     *
     * @example
     * $filtered = $this->filterAddresses($hostAddresses, true, false);
     * // Returns only IPv4 addresses
     */
    private function filterAddresses(array $hostAddresses, bool $useIPv4, bool $useIPv6): array
    {
        /** @var array<int, string> $v4 */
        $v4 = $hostAddresses["v4"] ?? [];
        /** @var array<int, string> $v6 */
        $v6 = $hostAddresses["v6"] ?? [];

        return match (true) {
            $useIPv4 && $useIPv6 => array_merge($v4, $v6),
            $useIPv4 => $v4,
            default => $v6
        };
    }

    /**
     * Get network interface information
     *
     * Wrapper for PHP's net_get_interfaces() function.
     * Returns an array of all the network interfaces on the machine.
     *
     * @return array<string, array<string, mixed>> Network interface information
     * @see https://www.php.net/manual/en/function.net-get-interfaces.php
     */
    public function getInterfaces(): array
    {
        $interfaces = net_get_interfaces();
        if ($interfaces === false) {
            return [];
        }

        return $interfaces;
    }
}