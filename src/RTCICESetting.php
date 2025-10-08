<?php

namespace Webrtc\ICE;

use Webrtc\Exception\InvalidArgumentException;
use Webrtc\ICE\Enum\IceRole;
use Webrtc\ICE\Enum\TransportPolicyType;

/**
 * The `RTCICESetting` class defines advanced configuration options
 * for the ICE (Interactive Connectivity Establishment) layer.
 *
 * It controls aspects such as port ranges, transport policy,
 * NAT mapping, ICE roles, and IP version usage.
 */
class RTCICESetting
{
    /**
     * Port range used for ICE candidate gathering.
     * If null, the system assigns ports automatically.
     *
     * @var array<int,int>|null [minPort, maxPort] or null if unset
     */
    private ?array $icePortRange = null;

    /**
     * Defines the policy for allowed transport types (e.g., ALL, RELAY).
     *
     * @var TransportPolicyType
     */
    private TransportPolicyType $transportPolicy = TransportPolicyType::ALL;

    /**
     * A list of 1:1 NAT public-to-private IP address mappings.
     * Useful for servers behind NAT that need predictable candidate addresses.
     *
     * @var array<string,string>|null Array of mappings like ['privateIp' => 'publicIp']
     */
    private ?array $nat1to1 = null;

    /**
     * Whether the agent should operate in ICE-Lite mode.
     * ICE-Lite agents only respond to connectivity checks.
     *
     * @var bool
     */
    private bool $iceLite = false;

    /**
     * Defines the current ICE role (Controlling or Controlled).
     * Primarily used for testing or forcing specific behavior.
     *
     * @var IceRole
     */
    private IceRole $iceRole = IceRole::Controlling;

    /**
     * Whether to gather and use IPv4 candidates.
     *
     * @var bool
     */
    private bool $useIPv4 = true;

    /**
     * Whether to gather and use IPv6 candidates.
     *
     * @var bool
     */
    private bool $useIPv6 = true;

    /**
     * Gets the configured ICE port range.
     *
     * @return array<int,int>|null The [minPort, maxPort] range or null if not set
     */
    public function getIcePortRange(): ?array
    {
        return $this->icePortRange;
    }

    /**
     * Sets the ICE port range used for candidate gathering.
     *
     * @param int $minPort Minimum port number (must be >= 1024)
     * @param int $maxPort Maximum port number (must be <= 65535)
     * @return void
     * @throws InvalidArgumentException If the range is invalid or too narrow
     */
    public function setIcePortRange(int $minPort, int $maxPort): void
    {
        if ($maxPort - $minPort < 100) {
            throw new InvalidArgumentException("maxPort - minPort must be greater than 100");
        }

        if ($minPort < 1024 || $maxPort > 65535 || $minPort > $maxPort) {
            throw new InvalidArgumentException("Invalid port range [$minPort, $maxPort]");
        }

        $this->icePortRange = [$minPort, $maxPort];
    }

    /**
     * Gets the current transport policy.
     *
     * @return TransportPolicyType The transport policy (e.g., ALL, RELAY)
     */
    public function getTransportPolicy(): TransportPolicyType
    {
        return $this->transportPolicy;
    }

    /**
     * Sets the transport policy for ICE candidate gathering.
     *
     * @param TransportPolicyType $transportPolicy The desired transport policy
     * @return void
     */
    public function setTransportPolicy(TransportPolicyType $transportPolicy): void
    {
        $this->transportPolicy = $transportPolicy;
    }

    /**
     * Gets the 1:1 NAT mapping configuration.
     *
     * @return array<string,string>|null NAT mappings or null if not configured
     */
    public function getNat1to1(): ?array
    {
        return $this->nat1to1;
    }

    /**
     * Sets the 1:1 NAT mapping configuration.
     *
     * @param array<string,string>|null $nat1to1 Array of ['privateIp' => 'publicIp'] or null
     * @return void
     */
    public function setNat1to1(?array $nat1to1): void
    {
        $this->nat1to1 = $nat1to1;
    }

    /**
     * Checks if ICE-Lite mode is enabled.
     *
     * @return bool True if ICE-Lite mode is enabled, false otherwise
     */
    public function isIceLite(): bool
    {
        return $this->iceLite;
    }

    /**
     * Enables or disables ICE-Lite mode.
     *
     * @param bool $iceLite Whether to enable ICE-Lite mode
     * @return void
     */
    public function setIceLite(bool $iceLite): void
    {
        $this->iceLite = $iceLite;
    }

    /**
     * Gets the current ICE role.
     *
     * @return IceRole The ICE role (Controlling or Controlled)
     */
    public function getIceRole(): IceRole
    {
        return $this->iceRole;
    }

    /**
     * Sets the ICE role.
     *
     * @param IceRole $iceRole The ICE role to set
     * @return void
     */
    public function setIceRole(IceRole $iceRole): void
    {
        $this->iceRole = $iceRole;
    }

    /**
     * Checks whether IPv4 candidates are used.
     *
     * @return bool True if IPv4 candidates are used, false otherwise
     */
    public function isUseIPv4(): bool
    {
        return $this->useIPv4;
    }

    /**
     * Enables or disables the use of IPv4 candidates.
     *
     * @param bool $useIPv4 Whether to use IPv4 candidates
     * @return void
     */
    public function setUseIPv4(bool $useIPv4): void
    {
        $this->useIPv4 = $useIPv4;
    }

    /**
     * Checks whether IPv6 candidates are used.
     *
     * @return bool True if IPv6 candidates are used, false otherwise
     */
    public function isUseIPv6(): bool
    {
        return $this->useIPv6;
    }

    /**
     * Enables or disables the use of IPv6 candidates.
     *
     * @param bool $useIPv6 Whether to use IPv6 candidates
     * @return void
     */
    public function setUseIPv6(bool $useIPv6): void
    {
        $this->useIPv6 = $useIPv6;
    }
}
