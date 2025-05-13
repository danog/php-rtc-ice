<?php

namespace Tests\Webrtc\ICE;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Webrtc\ICE\Enum\IceGatheringState;
use Webrtc\ICE\IceProtocolParser;
use Webrtc\ICE\RTCIceCandidate;
use Webrtc\ICE\RTCIceConnection;
use Webrtc\ICE\RTCIceGatherer;
use PHPUnit\Framework\TestCase;
use Webrtc\ICE\RTCIceProtocolConfiguration;
use Webrtc\ICE\RTCIceServer;
use Webrtc\ICE\Trait\NetworkAdapter;
use Webrtc\ICE\Utils;
use Webrtc\STUN\BaseProtocol;
use Webrtc\STUN\Datagram;
use Webrtc\STUN\Stun;
use Webrtc\TURN\TCPConnection;

#[UsesClass(IceProtocolParser::class)]
#[UsesClass(RTCIceCandidate::class)]
#[UsesClass(RTCIceConnection::class)]
#[UsesClass(RTCIceProtocolConfiguration::class)]
#[UsesClass(NetworkAdapter::class)]
#[UsesClass(Utils::class)]
#[UsesClass(BaseProtocol::class)]
#[UsesClass(Datagram::class)]
#[UsesClass(Stun::class)]
#[UsesClass(TCPConnection::class)]
#[UsesClass(RTCIceServer::class)]
#[CoversClass(RTCIceGatherer::class)]
class RTCIceGathererTest extends TestCase
{
    public function testGather()
    {
        $gatherer = new RTCIceGatherer([]);

        $this->assertEquals(IceGatheringState::new, $gatherer->getState());
        $this->assertEquals([], $gatherer->getLocalCandidates());

        $gatherer->gather();

        $this->assertEquals(IceGatheringState::complete, $gatherer->getState());
        $this->assertGreaterThan(0, count($gatherer->getLocalCandidates()));

        $gatherer->close();
    }

    public function testDefaultIceServers()
    {
        $rtcIceServer = new RTCIceServer();
        $rtcIceServer->setUrls('stun:stun.l.google.com:19302');

        $defaultIceServers = new RTCIceGatherer([$rtcIceServer]);

        $this->assertCount(1, $defaultIceServers->getIceServes());
        $this->assertEquals($rtcIceServer->getUrls(), $defaultIceServers->getIceServes()[0]->getUrls());
    }
}
