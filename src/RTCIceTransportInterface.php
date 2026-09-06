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

use Evenement\EventEmitterInterface;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\ICE\Enum\IceRole;
use Webrtc\ICE\Listener\IceTransportDataListener;
use Webrtc\ICE\Listener\IceTransportDisconnectListener;

interface RTCIceTransportInterface extends EventEmitterInterface
{
    /** Register a listener for application data arriving over the transport. */
    public function addDataListener(IceTransportDataListener $listener): void;

    /** Remove a previously registered data listener. */
    public function removeDataListener(IceTransportDataListener $listener): void;

    /** Register a listener notified when the transport reports a disconnecting close/error. */
    public function addDisconnectListener(IceTransportDisconnectListener $listener): void;

    public function send(string $bytes): void;

    public function getRole(): IceRole;

    public function addRemoteCandidate(RTCIceCandidate $candidate): void;

    public function getIceGatherer(): RTCIceGathererInterface;

    public function isRoleSet(): bool;

    public function setRoleSet(bool $roleSet): void;

    public function getIceConnection(): RTCIceConnectionInterface;

    public function start(RTCIceParameters $remoteIceParameters): void;

    public function stop(): void;
}
