<?php

namespace Webrtc\ICE\Listener;

use Webrtc\ICE\Enum\IceTransportState;

/**
 * Notified when an {@see \Webrtc\ICE\RTCIceTransport} changes its transport state.
 *
 * A typed replacement for the former Evenement "statechange" event: the listener object is
 * registered on the transport and, being an ordinary object rather than a closure, survives a
 * serialize cycle as part of the graph with no wrapper.
 */
interface IceTransportStateChangeListener
{
    public function onIceTransportStateChange(IceTransportState $state): void;
}
