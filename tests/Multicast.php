<?php

namespace Tests\Webrtc\ICE;

/**
 * Decides whether multicast responses can actually reach this host.
 *
 * mDNS sends to 224.0.0.251 and receives on a socket that has joined that group
 * (RFC 6762). Binding the socket to the group address itself is not how the
 * protocol works, and it fails on GitHub-hosted runners (Windows rejects it;
 * Linux loopback often has no MULTICAST flag). Joining the group on 0.0.0.0
 * matches the production resolver and the dummy/ethernet interfaces CI enables.
 */
final class Multicast
{
    private const GROUP = '224.0.0.251';

    private static ?bool $available = null;

    /**
     * Whether a multicast datagram sent locally is delivered back locally.
     */
    public static function isAvailable(): bool
    {
        return self::$available ??= self::probe();
    }

    public static function skipReason(): string
    {
        return 'This host does not deliver multicast datagrams locally, which mDNS requires. '
            . 'Loopback usually has no MULTICAST flag; run these tests where group traffic works.';
    }

    private static function probe(): bool
    {
        if (extension_loaded('sockets') && self::probeWithSockets()) {
            return true;
        }

        return self::probeWithStreams();
    }

    /**
     * Bind 0.0.0.0, join 224.0.0.251, send a datagram to the group, see if it arrives.
     */
    private static function probeWithSockets(): bool
    {
        $port = 5354;
        $receiver = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($receiver === false) {
            return false;
        }

        socket_set_option($receiver, SOL_SOCKET, SO_REUSEADDR, 1);
        if (defined('SO_REUSEPORT')) {
            @socket_set_option($receiver, SOL_SOCKET, SO_REUSEPORT, 1);
        }

        if (!@socket_bind($receiver, '0.0.0.0', $port)) {
            socket_close($receiver);

            return false;
        }

        $membership = ['group' => self::GROUP, 'interface' => '0.0.0.0'];
        $joined = @socket_set_option($receiver, IPPROTO_IP, MCAST_JOIN_GROUP, $membership);
        if ($joined === false) {
            $joined = @socket_set_option($receiver, IPPROTO_IP, IP_ADD_MEMBERSHIP, $membership);
        }
        if ($joined === false) {
            socket_close($receiver);

            return false;
        }

        @socket_set_option($receiver, IPPROTO_IP, IP_MULTICAST_LOOP, 1);
        socket_set_nonblock($receiver);

        $sender = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sender === false) {
            socket_close($receiver);

            return false;
        }
        @socket_set_option($sender, IPPROTO_IP, IP_MULTICAST_LOOP, 1);
        @socket_set_option($sender, IPPROTO_IP, IP_MULTICAST_TTL, 1);
        $sent = @socket_sendto($sender, 'probe', 5, 0, self::GROUP, $port);
        if ($sent === false) {
            socket_close($sender);
            socket_close($receiver);

            return false;
        }

        $read = [$receiver];
        $write = $except = [];
        $delivered = @socket_select($read, $write, $except, 0, 250_000) > 0;

        socket_close($sender);
        socket_close($receiver);

        return $delivered;
    }

    /**
     * Bind the socket to the group address itself. Works on hosts whose loopback
     * (or dummy) interface has the MULTICAST flag; fails on Windows and on GHA
     * Ubuntu without extra interfaces.
     */
    private static function probeWithStreams(): bool
    {
        $receiver = @stream_socket_server(
            'udp://' . self::GROUP . ':5354',
            $errno,
            $errstr,
            STREAM_SERVER_BIND
        );

        if ($receiver === false) {
            return false;
        }

        stream_set_blocking($receiver, false);

        $sender = @stream_socket_client('udp://' . self::GROUP . ':5354', $errno, $errstr);
        if ($sender === false) {
            fclose($receiver);

            return false;
        }

        fwrite($sender, 'probe');

        $read = [$receiver];
        $write = $except = [];
        $delivered = @stream_select($read, $write, $except, 0, 250_000) > 0;

        fclose($sender);
        fclose($receiver);

        return $delivered;
    }
}
