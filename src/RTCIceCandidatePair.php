<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\ICE;

use Amp\Socket\InternetAddress;
use Webrtc\ICE\Enum\RTCIceCandidatePairStats;
use Webrtc\STUN\IceConnectionProtocolInterface;

/**
 * RTCIceCandidatePair Class
 *
 * Represents a pair of ICE candidates (local and remote) that form a potential
 * connection path for WebRTC communication. This class manages the state of the
 * candidate pair and provides information about the connection endpoints.
 *
 * ICE candidate pairs are created by combining a local candidate with a remote
 * candidate, and they undergo various states during the ICE connectivity checks
 * as defined in RFC 8445. A nominated candidate pair is the one selected for
 * actual data transmission.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8445#section-6.1.2.2
 */
final class RTCIceCandidatePair
{
    /**
     * Indicates whether the local agent has nominated this candidate pair
     *
     * A nominated pair is the candidate pair selected for data transmission
     *
     * @var bool
     */
    private bool $nominated = false;

    /**
     * Indicates whether the remote agent has nominated this candidate pair
     *
     * @var bool
     */
    private bool $remoteNominated = false;

    /**
     * The current state of this candidate pair in the ICE connectivity check process
     *
     * @var RTCIceCandidatePairStats
     */
    private RTCIceCandidatePairStats $state = RTCIceCandidatePairStats::FROZEN;

    /** Smoothed round-trip time of the binding requests sent on this pair, in seconds. */
    private ?float $rtt = null;

    /** How many round-trip times were measured. */
    private int $rttSamples = 0;

    /** When a binding request sent on this pair was last answered (microtime). */
    private ?float $lastResponseAt = null;

    /** When a keepalive binding request was last sent on this pair (microtime). */
    private ?float $lastPingAt = null;

    /** Whether a keepalive binding request is waiting for its response. */
    private bool $pinging = false;

    /**
     * Creates a new RTCIceCandidatePair instance
     *
     * @param IceConnectionProtocolInterface $protocol The protocol used for connection
     *                                                (contains the local candidate)
     * @param RTCIceCandidate $remoteCandidate The remote ICE candidate
     */
    public function __construct(private readonly IceConnectionProtocolInterface $protocol, private readonly RTCIceCandidate $remoteCandidate)
    {
    }

    /**
     * Gets the component ID of the local candidate
     *
     * The component ID identifies the specific component within an ICE session
     * (e.g., 1 for RTP, 2 for RTCP)
     *
     * @return int The component ID
     */
    public function getComponentId(): int
    {
        $candidate = $this->protocol->getCandidate();
        assert($candidate !== null);
        return $candidate->getComponentId();
    }

    /**
     * Gets the local candidate's address information
     *
     * @return InternetAddress The local candidate address
     */
    public function getLocalAddress(): InternetAddress
    {
        $candidate = $this->protocol->getCandidate();
        assert($candidate !== null);
        /** @var int<0, 65535> $port */
        $port = $candidate->getPort();
        return new InternetAddress($candidate->getHost(), $port);
    }

    /**
     * Gets the local ICE candidate object
     *
     * @return RTCIceCandidate The local ICE candidate
     */
    public function getLocalCandidate(): RTCIceCandidate
    {
        $candidate = $this->protocol->getCandidate();
        assert($candidate instanceof RTCIceCandidate);
        return $candidate;
    }

    /**
     * Gets the remote candidate's address information
     *
     * @return InternetAddress The remote candidate address
     */
    public function getRemoteAddress(): InternetAddress
    {
        /** @var int<0, 65535> $port */
        $port = $this->remoteCandidate->getPort();
        return new InternetAddress($this->remoteCandidate->getHost(), $port);
    }

    /**
     * Gets the protocol interface used for the ICE connection
     *
     * The protocol interface provides access to the underlying transport protocol
     * (UDP, TCP, etc.) and contains the local candidate information
     *
     * @return IceConnectionProtocolInterface The protocol interface
     */
    public function getProtocol(): IceConnectionProtocolInterface
    {
        return $this->protocol;
    }

    /**
     * Gets the remote ICE candidate object
     *
     * @return RTCIceCandidate The remote ICE candidate
     */
    public function getRemoteCandidate(): RTCIceCandidate
    {
        return $this->remoteCandidate;
    }

    /**
     * Checks if the remote agent has nominated this candidate pair
     *
     * Remote nomination occurs when the remote peer has selected this candidate pair
     * for data transmission
     *
     * @return bool True if nominated by the remote agent, false otherwise
     */
    public function isRemoteNominated(): bool
    {
        return $this->remoteNominated;
    }

    /**
     * Sets the remote nomination status for this candidate pair
     *
     * @param bool $remoteNominated True to mark as nominated by the remote agent
     * @return void
     */
    public function setRemoteNominated(bool $remoteNominated): void
    {
        $this->remoteNominated = $remoteNominated;
    }

    /**
     * Checks if the local agent has nominated this candidate pair
     *
     * Local nomination occurs when the local peer has selected this candidate pair
     * for data transmission
     *
     * @return bool True if nominated by the local agent, false otherwise
     */
    public function isNominated(): bool
    {
        return $this->nominated;
    }

    /**
     * Sets the local nomination status for this candidate pair
     *
     * @param bool $nominated True to mark as nominated by the local agent
     * @return void
     */
    public function setNominated(bool $nominated): void
    {
        $this->nominated = $nominated;
    }

    /**
     * Returns a string representation of this candidate pair
     *
     * Includes local address, remote address, and current state for debugging
     *
     * @return string The string representation
     */
    public function __toString(): string
    {
        return sprintf("CandidatePair(Local Address: %s -> Remote Address: %s | State: %s)", $this->getLocalAddress(), $this->getRemoteAddress(), $this->state->name);
    }

    /**
     * Gets the current state of this candidate pair
     *
     * The state represents the progress of the ICE connectivity check for this pair
     * (e.g., FROZEN, IN_PROGRESS, SUCCEEDED, FAILED)
     *
     * @return RTCIceCandidatePairStats The current state
     */
    public function getState(): RTCIceCandidatePairStats
    {
        return $this->state;
    }

    /**
     * Sets the state of this candidate pair
     *
     * Updates the state as the ICE connectivity check progresses
     *
     * @param RTCIceCandidatePairStats $state The new state
     * @return void
     */
    public function setState(RTCIceCandidatePairStats $state): void
    {
        $this->state = $state;
    }

    /**
     * Records the response to a binding request sent on this pair.
     *
     * @param float $rtt How long the response took, in seconds.
     */
    public function recordResponse(float $rtt, float $now): void
    {
        // Smoothed like TCP's (RFC 6298), but reacting faster to a path getting slower.
        $this->rtt = $this->rtt === null ? $rtt : 0.75 * $this->rtt + 0.25 * $rtt;
        $this->rttSamples++;
        $this->lastResponseAt = $now;
    }

    /**
     * Considers this pair answered now, if it never was: for a pair that became valid without a timed request.
     */
    public function touch(float $now): void
    {
        $this->lastResponseAt ??= $now;
    }

    /**
     * Gets the smoothed round-trip time of this pair, in seconds, if measured.
     */
    public function getRtt(): ?float
    {
        return $this->rtt;
    }

    /**
     * Gets how many round-trip times were measured on this pair.
     */
    public function getRttSamples(): int
    {
        return $this->rttSamples;
    }

    /**
     * Gets when a binding request sent on this pair was last answered (microtime), if ever.
     */
    public function getLastResponseAt(): ?float
    {
        return $this->lastResponseAt;
    }

    /**
     * Whether a binding request sent on this pair was answered in the specified number of seconds.
     */
    public function respondedWithin(float $seconds, float $now): bool
    {
        return $this->lastResponseAt !== null && $now - $this->lastResponseAt <= $seconds;
    }

    /**
     * Gets when a keepalive binding request was last sent on this pair (microtime), if ever.
     */
    public function getLastPingAt(): ?float
    {
        return $this->lastPingAt;
    }

    /**
     * Whether a keepalive binding request is waiting for its response.
     */
    public function isPinging(): bool
    {
        return $this->pinging;
    }

    /**
     * Records that a keepalive binding request was sent, or that it got answered or timed out.
     */
    public function setPinging(bool $pinging, float $now): void
    {
        $this->pinging = $pinging;
        if ($pinging) {
            $this->lastPingAt = $now;
        }
    }

    /**
     * Restarts the liveness bookkeeping, as after the agent was serialized and restored: the timestamps
     * of another process mean nothing, and the requests it was waiting for won't be answered to it.
     */
    public function resetLiveness(float $now): void
    {
        if ($this->lastResponseAt !== null) {
            $this->lastResponseAt = $now;
        }
        $this->lastPingAt = null;
        $this->pinging = false;
    }
}
