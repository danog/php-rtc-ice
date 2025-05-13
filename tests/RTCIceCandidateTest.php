<?php

namespace Tests\Webrtc\ICE;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\ICE\RTCIceCandidate;
use PHPUnit\Framework\TestCase;
use Webrtc\ICE\Utils;

#[UsesClass(Utils::class)]
#[CoversClass(RTCIceCandidate::class)]
class RTCIceCandidateTest extends TestCase
{
    public function testCanPairIpv4()
    {
        $candidateA = RTCIceCandidate::parseSDP("6815297761 1 udp 659136 1.2.3.4 31102 typ host generation 0");
        $candidateB = RTCIceCandidate::parseSDP("6815297761 1 udp 659136 1.2.3.4 12345 typ host generation 0");
        $this->assertTrue($candidateA->isPairableWith($candidateB));
    }

    public function testCanPairIpv4CaseInsensitive()
    {
        $candidateA = RTCIceCandidate::parseSDP("6815297761 1 udp 659136 1.2.3.4 31102 typ host generation 0");
        $candidateB = RTCIceCandidate::parseSDP("6815297761 1 UDP 659136 1.2.3.4 12345 typ host generation 0");
        $this->assertTrue($candidateA->isPairableWith($candidateB));
    }

    public function testCanPairIpv6()
    {
        $candidateA = RTCIceCandidate::parseSDP("6815297761 1 udp 659136 2a02:0db8:85a3:0000:0000:8a2e:0370:7334 31102 typ host generation 0");
        $candidateB = RTCIceCandidate::parseSDP("6815297761 1 udp 659136 2a02:0db8:85a3:0000:0000:8a2e:0370:7334 12345 typ host generation 0");
        $this->assertTrue($candidateA->isPairableWith($candidateB));
    }

    public function testCannotPairIpv4Ipv6()
    {
        $candidateA = RTCIceCandidate::parseSDP("6815297761 1 udp 659136 1.2.3.4 31102 typ host generation 0");
        $candidateB = RTCIceCandidate::parseSDP("6815297761 1 udp 659136 2a02:0db8:85a3:0000:0000:8a2e:0370:7334 12345 typ host generation 0");
        $this->assertFalse($candidateA->isPairableWith($candidateB));
    }

    public function testCannotPairDifferentComponents()
    {
        $candidateA = RTCIceCandidate::parseSDP("6815297761 1 udp 659136 1.2.3.4 31102 typ host generation 0");
        $candidateB = RTCIceCandidate::parseSDP("6815297761 2 udp 659136 1.2.3.4 12345 typ host generation 0");
        $this->assertFalse($candidateA->isPairableWith($candidateB));
    }

    public function testCannotPairDifferentTransports()
    {
        $candidateA = RTCIceCandidate::parseSDP("6815297761 1 udp 659136 1.2.3.4 31102 typ host generation 0");
        $candidateB = RTCIceCandidate::parseSDP("6815297761 1 tcp 659136 1.2.3.4 12345 typ host generation 0 tcptype active");
        $this->assertFalse($candidateA->isPairableWith($candidateB));
    }

    public function testFromSdpUdp()
    {
        $candidate = RTCIceCandidate::parseSDP("6815297761 1 udp 659136 1.2.3.4 31102 typ host generation 0");
        $this->assertEquals("6815297761", $candidate->getFoundation());
        $this->assertEquals(1, $candidate->getComponentId());
        $this->assertEquals("udp", $candidate->getTransport()->name);
        $this->assertEquals(659136, $candidate->getPriority());
        $this->assertEquals("1.2.3.4", $candidate->getHost());
        $this->assertEquals(31102, $candidate->getPort());
        $this->assertEquals("host", $candidate->getType()->name);
        $this->assertEquals(0, $candidate->getGeneration());

        $this->assertEquals(
            "6815297761 1 udp 659136 1.2.3.4 31102 typ host generation 0",
            $candidate->convert2SDP()
        );
    }

    public function testFromSdpUdpSrflx()
    {
        $candidate = RTCIceCandidate::parseSDP("1 1 UDP 1686052863 1.2.3.4 42705 typ srflx raddr 192.168.1.101 rport 42705");
        $this->assertEquals("1", $candidate->getFoundation());
        $this->assertEquals(1, $candidate->getComponentId());
        $this->assertEquals("udp", $candidate->getTransport()->name);
        $this->assertEquals(1686052863, $candidate->getPriority());
        $this->assertEquals("1.2.3.4", $candidate->getHost());
        $this->assertEquals(42705, $candidate->getPort());
        $this->assertEquals("srflx", $candidate->getType()->name);
        $this->assertEquals("192.168.1.101", $candidate->getRelatedAddress());
        $this->assertEquals(42705, $candidate->getRelatedPort());
        $this->assertNull($candidate->getGeneration());

        $this->assertEquals(
            "1 1 udp 1686052863 1.2.3.4 42705 typ srflx raddr 192.168.1.101 rport 42705",
            $candidate->convert2SDP()
        );
    }

    public function testFromSdpTcp()
    {
        $candidate = RTCIceCandidate::parseSDP("1936595596 1 tcp 1518214911 1.2.3.4 9 typ host tcptype active generation 0 network-id 1 network-cost 10");
        $this->assertEquals("1936595596", $candidate->getFoundation());
        $this->assertEquals(1, $candidate->getComponentId());
        $this->assertEquals("tcp", $candidate->getTransport()->name);
        $this->assertEquals(1518214911, $candidate->getPriority());
        $this->assertEquals("1.2.3.4", $candidate->getHost());
        $this->assertEquals(9, $candidate->getPort());
        $this->assertEquals("host", $candidate->getType()->name);
        $this->assertEquals("active", $candidate->getTcpType());
        $this->assertEquals(0, $candidate->getGeneration());

        $this->assertEquals(
            "1936595596 1 tcp 1518214911 1.2.3.4 9 typ host tcptype active generation 0",
            $candidate->convert2SDP()
        );
    }

    public function testFromSdpNoGeneration()
    {
        $candidate = RTCIceCandidate::parseSDP("6815297761 1 udp 659136 1.2.3.4 31102 typ host");
        $this->assertEquals("6815297761", $candidate->getFoundation());
        $this->assertEquals(1, $candidate->getComponentId());
        $this->assertEquals("udp", $candidate->getTransport()->name);
        $this->assertEquals(659136, $candidate->getPriority());
        $this->assertEquals("1.2.3.4", $candidate->getHost());
        $this->assertEquals(31102, $candidate->getPort());
        $this->assertEquals("host", $candidate->getType()->name);
        $this->assertNull($candidate->getGeneration());

        $this->assertEquals(
            "6815297761 1 udp 659136 1.2.3.4 31102 typ host",
            $candidate->convert2SDP()
        );
    }

    public function testFromSdpTruncated()
    {
        $this->expectException(InvalidArgumentException::class);
        RTCIceCandidate::parseSDP("6815297761 1 udp 659136 1.2.3.4 31102 typ");
    }

    public function testToString()
    {
        $candidate = RTCIceCandidate::parseSDP("6815297761 1 udp 659136 1.2.3.4 31102 typ host generation 0");
        $this->assertEquals(
            "6815297761 1 udp 659136 1.2.3.4 31102 typ host generation 0",
            (string)$candidate
        );
    }
}
