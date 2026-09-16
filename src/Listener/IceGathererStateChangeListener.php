<?php

namespace Webrtc\ICE\Listener;

use Webrtc\ICE\Enum\IceGatheringState;

/**
 * Notified when an {@see \Webrtc\ICE\RTCIceGatherer} changes its gathering state.
 *
 * A typed replacement for the former Evenement "statechange" event: the listener object is
 * registered on the gatherer and, being an ordinary object rather than a closure, survives a
 * serialize cycle as part of the graph with no wrapper.
 */
interface IceGathererStateChangeListener
{
    public function onIceGathererStateChange(IceGatheringState $state): void;
}
