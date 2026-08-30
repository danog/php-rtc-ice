<?php

namespace Tests\Webrtc\ICE;

/**
 * Decides whether multicast responses can actually reach this host.
 *
 * mDNS only works if a datagram sent to 224.0.0.251 comes back to a socket bound to the
 * group on the same machine. That is a property of the network stack as the current process
 * sees it, and it is normally only absent in constrained environments where group traffic is
 * dropped (isolated containers, some CI networks). The check probes the socket directly, in
 * the current network namespace, rather than guessing from host state such as /proc or /sys,
 * so it reflects the environment the tests will actually run in.
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
