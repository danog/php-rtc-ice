<?php

namespace Webrtc\ICE\Listener;

/**
 * Notified when an {@see \Webrtc\ICE\RTCIceConnection} closes (the former "onClose" event).
 */
interface IceConnectionClosedListener
{
    public function onIceConnectionClosed(): void;
}
