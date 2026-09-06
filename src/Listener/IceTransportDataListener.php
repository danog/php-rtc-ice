<?php

namespace Webrtc\ICE\Listener;

/**
 * Receives application data delivered by an {@see \Webrtc\ICE\RTCIceTransportInterface}.
 *
 * Replaces the former Evenement "data" event that the DTLS transport and the DTLS handshake both
 * listened to.
 */
interface IceTransportDataListener
{
    public function onIceTransportData(string $data, int $componentId): void;
}
