<?php

namespace Webrtc\ICE\Listener;

/**
 * Notified when an {@see \Webrtc\ICE\RTCIceTransportInterface} reports a disconnecting close or
 * error, replacing the former Evenement "close"/"error" events.
 */
interface IceTransportDisconnectListener
{
    public function onIceTransportDisconnect(): void;
}
