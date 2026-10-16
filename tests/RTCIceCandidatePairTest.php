<?php

declare(strict_types=1);

namespace Tests\Webrtc\ICE;

use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Webrtc\STUN\IceConnectionProtocolInterface;
use Webrtc\ICE\RTCIceCandidate;
use Webrtc\ICE\RTCIceCandidatePair;

#[CoversClass(RTCIceCandidatePair::class)]
#[UsesClass(RTCIceCandidate::class)]
final class RTCIceCandidatePairTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testTheRemoteAddressIsReusedUntilTheCandidateChanges(): void
    {
        $remote = new RTCIceCandidate(1);
        $remote->setHost('192.0.2.1');
        $remote->setPort(3478);
        $pair = new RTCIceCandidatePair(Mockery::mock(IceConnectionProtocolInterface::class), $remote);

        $address = $pair->getRemoteAddress();
        $this->assertSame('192.0.2.1:3478', $address->toString());
        // Used for every packet sent: not created again.
        $this->assertSame($address, $pair->getRemoteAddress());

        $remote->setPort(3479);
        $this->assertSame('192.0.2.1:3479', $pair->getRemoteAddress()->toString());
        $remote->setHost('2001:db8::1');
        $this->assertSame('[2001:db8::1]:3479', $pair->getRemoteAddress()->toString());
        $this->assertSame($pair->getRemoteAddress(), $pair->getRemoteAddress());
    }
}
