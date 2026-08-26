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

use Random\RandomException;
use function parse_url;

/**
 * Utility class for ICE-related operations.
 */
class Utils
{
    /**
     * Generate a random alphanumeric string of a given length.
     *
     * @param int $length The desired length of the string.
     * @return string The generated random string.
     * @throws RandomException If randomness cannot be generated securely.
     */
    public static function getRandomString(int $length): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $index = random_int(0, $charactersLength - 1);
            $randomString .= $characters[$index];
        }

        return $randomString;
    }

    /**
     * Determine the IP version of a given IP address.
     *
     * @param string $ip The IP address to check.
     * @return int|false Returns 4 for IPv4, 6 for IPv6, or false if invalid.
     */
    public static function IPVersion(string $ip): int|false
    {
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            return false;
        }

        return isset($packedIp[4]) ? 6 : 4;
    }

    /**
     * Generate a random positive 64-bit integer.
     *
     * @return string The random 64-bit integer as a decimal string.
     * @throws RandomException If randomness cannot be generated securely.
     */
    public static function generateRandom64BitInt(): string
    {
        $high = random_int(0, 0x7FFFFFFF);
        $low = random_int(0, 0xFFFFFFFF);

        // $high is capped to 31 bits, so the result always fits in a signed 64-bit integer.
        return (string) (($high << 32) | $low);
    }

    /**
     * Compare two 64-bit tiebreaker values as unsigned integers.
     *
     * PHP integers are signed, and the STUN ICE-CONTROLLING/ICE-CONTROLLED
     * attributes carry an unsigned 64-bit value, so a peer's tiebreaker with
     * the high bit set is unpacked as a negative int. When the two operands
     * share a sign the signed order already matches the unsigned order; when
     * the signs differ, the negative operand is the larger unsigned value.
     *
     * @param int $a The first tiebreaker.
     * @param int $b The second tiebreaker.
     * @return int -1 if $a < $b, 0 if equal, 1 if $a > $b (unsigned).
     */
    public static function compareUnsigned64(int $a, int $b): int
    {
        if (($a < 0) === ($b < 0)) {
            return $a <=> $b;
        }

        return $a < 0 ? 1 : -1;
    }

    /**
     * Parse an address string to extract host and port.
     *
     * @param string $address The input address (with or without a scheme).
     * @return array{0: string|null, 1: int|null} Array of host and port.
     */
    public static function parseAddressToHostPort(string $address): array
    {
        if (!str_contains($address, '://')) {
            $address = 'udp://' . $address;
        }

        $address = parse_url($address);

        return [trim($address['host'], '[]') ?? null, $address['port'] ?? null];
    }
}
