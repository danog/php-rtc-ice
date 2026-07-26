<?php

namespace Tests\Webrtc\ICE;

/**
 * Decides whether multicast responses can actually reach this host.
 *
 * mDNS only works if a datagram sent to 224.0.0.251 comes back to a socket bound to the
 * group on the same machine. That is not a property of the code under test but of the
 * network stack it runs on, and it is commonly absent: loopback usually has no MULTICAST
 * flag, and container and CI networks frequently drop group traffic. Without this check the
 * tests do not fail, they sit in a DNS retry loop until the query times out.
 */
final class Multicast
{
    private const GROUP = '224.0.0.251';
    private const IFF_MULTICAST = 0x1000;

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
        if (self::hasForeignResponder()) {
            return 'Another process on this host is already bound to the mDNS port, so queries '
                . 'are answered by whichever responder wins the race rather than by the test. '
                . 'Browsers and systemd-resolved both do this; run these tests on an isolated '
                . 'network namespace instead.';
        }

        return 'This host does not deliver multicast datagrams locally, which mDNS requires. '
            . 'Loopback usually has no MULTICAST flag; run these tests where group traffic works.';
    }

    /**
     * Whether something else already answers on the mDNS port.
     *
     * Binding the group succeeds regardless — the kernel lets every listener join — so the
     * only symptom is that the responder under test competes for queries with whatever else
     * is running, and the resolver may take the other answer or none at all. Detecting that
     * up front turns a ten second timeout into an explanation.
     */
    private static function hasForeignResponder(): bool
    {
        // /proc/net/udp lists the local address as HEX_ADDR:HEX_PORT; 5353 is 0x14E9.
        foreach (['/proc/net/udp', '/proc/net/udp6'] as $path) {
            $table = @file_get_contents($path);
            if ($table !== false && preg_match('/^\s*\d+:\s+\S+:14E9\s/mi', $table)) {
                return true;
            }
        }

        return false;
    }

    private static function probe(): bool
    {
        // A quick structural check first: with no multicast-capable interface at all there is
        // nothing to probe, and binding would only fail slowly.
        if (!self::hasMulticastInterface() || self::hasForeignResponder()) {
            return false;
        }

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

    private static function hasMulticastInterface(): bool
    {
        $interfaces = @glob('/sys/class/net/*/flags') ?: [];

        foreach ($interfaces as $path) {
            $flags = @file_get_contents($path);
            if ($flags !== false && (hexdec(trim($flags)) & self::IFF_MULTICAST) !== 0) {
                return true;
            }
        }

        // Not Linux, or /sys is not mounted: let the socket probe decide.
        return $interfaces === [];
    }
}
