<?php

namespace Webrtc\ICE\Listener;

/**
 * Receives application data delivered by an {@see \Webrtc\ICE\RTCIceConnection}.
 *
 * A typed replacement for the former Evenement "data" event: the listener object is registered on
 * the connection and, being an ordinary object rather than a closure, survives a serialize cycle
 * as part of the graph with no wrapper.
 */
interface IceConnectionDataListener
{
    public function onIceConnectionData(string $data, int $componentId): void;
}
