<?php

namespace Tests\Webrtc\ICE;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Webrtc\ICE\Enum\CandidateType;
use Webrtc\ICE\Enum\IceRole;
use Webrtc\ICE\Enum\TransportType;
use Webrtc\ICE\RTCIceCandidate;
use Webrtc\ICE\RTCIceConnection;
use Webrtc\ICE\RTCIceParameters;
use Webrtc\ICE\RTCIceProtocolConfiguration;
use Webrtc\ICE\RTCIceServer;

#[CoversNothing]
final class SerializationTest extends TestCase
{
    /**
     * @template T of object
     * @param T $object
     * @return T
     */
    private function cycle(object $object): object
    {
        $class = $object::class;
        $blob = serialize($object);
        unset($object);
        gc_collect_cycles();
        $restored = unserialize($blob);
        $this->assertInstanceOf($class, $restored);

        return $restored;
    }

    public function testValueObjectsSurviveSerializeCycle(): void
    {
        $candidate = new RTCIceCandidate(1);
        $candidate->setFoundation('f');
        $candidate->setTransport(TransportType::udp);
        $candidate->setPriority(1);
        $candidate->setHost('127.0.0.1');
        $candidate->setPort(9);
        $candidate->setType(CandidateType::host);

        $restored = $this->cycle($candidate);
        $this->assertSame('127.0.0.1', $restored->getHost());
        $this->assertSame(9, $restored->getPort());

        $params = new RTCIceParameters('ufrag', 'pwd');
        $this->assertSame('ufrag', $this->cycle($params)->usernameFragment);

        $server = new RTCIceServer();
        $server->setUrls(['stun:127.0.0.1:3478']);
        $this->cycle($server);

        $this->cycle(new RTCIceProtocolConfiguration());
    }

    public function testIceConnectionRebindsAfterSerializeCycle(): void
    {
        $connection = new RTCIceConnection(new RTCIceProtocolConfiguration(), IceRole::Controlling);
        $connection->gatherCandidates();
        $this->assertNotEmpty($connection->getLocalCandidates());

        $restored = $this->cycle($connection);
        $this->assertFalse($restored->isClosed());
        $this->assertNotEmpty($restored->getLocalCandidates());
        $restored->close();
        $connection->close();
    }

}
