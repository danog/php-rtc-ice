<?php


use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Webrtc\DTLS\RTCDtlsTransport;
use Webrtc\DTLS\TLS\Handshake;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\ICE\Enum\CandidateType;
use Webrtc\ICE\Enum\IceRole;
use Webrtc\ICE\Enum\IceTransportState;
use Webrtc\ICE\Enum\TransportType;
use Webrtc\ICE\IceProtocolParser;
use Webrtc\ICE\RTCIceCandidate;
use Webrtc\ICE\RTCIceCandidatePair;
use Webrtc\ICE\RTCIceConnection;
use Webrtc\ICE\RTCIceGatherer;
use Webrtc\ICE\RTCIceParameters;
use Webrtc\ICE\RTCIceProtocolConfiguration;
use Webrtc\ICE\RTCICESetting;
use Webrtc\ICE\RTCIceTransport;
use Webrtc\ICE\Trait\Mdns;
use Webrtc\ICE\Trait\NetworkAdapter;
use Webrtc\ICE\Utils;
use Webrtc\RTP\Receiver\RTCRtpReceiver;
use Webrtc\RTP\RTCRtpTransceiver;
use Webrtc\RTP\Sender\RTCRtpSender;
use Webrtc\SSL\Crypto\EC\EC;
use Webrtc\SSL\Crypto\X509;
use Webrtc\SSL\SSL\SSL;
use Webrtc\STUN\BaseProtocol;
use Webrtc\STUN\Datagram;
use Webrtc\STUN\Exception\TransactionException;
use Webrtc\STUN\Exception\TransactionTimeoutException;
use Webrtc\STUN\Message\Message;
use Webrtc\STUN\Message\MessageAttributeCollection;
use Webrtc\STUN\Message\MessageAttributeEncoder;
use Webrtc\STUN\Message\MessageIntegrity;
use Webrtc\STUN\Stun;
use Webrtc\STUN\Transaction;
use Webrtc\TURN\TurnTcpConnection;
use Webrtc\Webrtc\RTCPeerConnection;
use function Amp\async;
use function Amp\delay;

#[UsesClass(IceProtocolParser::class)]
#[UsesClass(RTCIceCandidate::class)]
#[UsesClass(RTCIceCandidatePair::class)]
#[UsesClass(RTCIceConnection::class)]
#[UsesClass(RTCIceGatherer::class)]
#[UsesClass(RTCIceParameters::class)]
#[UsesClass(RTCIceProtocolConfiguration::class)]
#[UsesClass(Mdns::class)]
#[UsesClass(NetworkAdapter::class)]
#[UsesClass(Utils::class)]
#[UsesClass(BaseProtocol::class)]
#[UsesClass(Datagram::class)]
#[UsesClass(Message::class)]
#[UsesClass(MessageAttributeCollection::class)]
#[UsesClass(MessageAttributeEncoder::class)]
#[UsesClass(MessageIntegrity::class)]
#[UsesClass(Stun::class)]
#[UsesClass(Transaction::class)]
#[UsesClass(\Webrtc\STUN\Utils::class)]
#[UsesClass(TurnTcpConnection::class)]
#[UsesClass(TransactionException::class)]
#[UsesClass(TransactionTimeoutException::class)]
#[UsesClass(RTCDtlsTransport::class)]
#[UsesClass(RTCRtpTransceiver::class)]
#[UsesClass(RTCRtpReceiver::class)]
#[UsesClass(RTCRtpSender::class)]
#[UsesClass(EC::class)]
#[UsesClass(X509::class)]
#[UsesClass(RTCPeerConnection::class)]
#[UsesClass(Handshake::class)]
#[UsesClass(SSL::class)]
#[CoversClass(RTCIceTransport::class)]
class RTCIceTransportTest extends TestCase
{

    public function testConstruct()
    {
        $gatherer = new RTCIceGatherer([]);
        $transport = new RTCIceTransport($gatherer);

        $this->assertEquals(IceTransportState::new, $transport->getState());
        $this->assertEquals([], $transport->getRemoteCandidates());

        $candidate = new RTCIceCandidate(1);
        $candidate->setHost('192.168.99.7');
        $candidate->setPort(33543);
        $candidate->setPriority(2122252543);
        $candidate->setFoundation(0);
        $candidate->setTransport(TransportType::udp);
        $candidate->setType(CandidateType::host);

        $transport->addRemoteCandidate($candidate);
        $this->assertEquals([$candidate], $transport->getRemoteCandidates());

        $transport->endRemoteCandidate();
        $this->assertEquals([$candidate], $transport->getRemoteCandidates());
    }

    public function testConnect()
    {
        $iceSetting1 = new RTCICESetting;
        $iceSetting1->setIceRole(IceRole::Controlling);
        $gatherer1 = new RTCIceGatherer([], $iceSetting1);
        $transport1 = new RTCIceTransport($gatherer1);

        $iceSetting2 = new RTCICESetting;
        $iceSetting2->setIceRole(IceRole::Controlled);
        $gatherer2 = new RTCIceGatherer([], $iceSetting2);
        $transport2 = new RTCIceTransport($gatherer2);

        $gatherer1->gather();
        $gatherer2->gather();

        foreach ($gatherer2->getLocalCandidates() as $candidate) {
            $transport1->addRemoteCandidate($candidate);
        }
        foreach ($gatherer1->getLocalCandidates() as $candidate) {
            $transport2->addRemoteCandidate($candidate);
        }

        $this->assertEquals(IceTransportState::new, $transport1->getState());
        $this->assertEquals(IceTransportState::new, $transport2->getState());

        $this->asyncConnect($transport1, $transport2, $gatherer1, $gatherer2);

        $this->assertEquals(IceTransportState::complete, $transport1->getState());
        $this->assertEquals(IceTransportState::complete, $transport2->getState());

        $transport1->stop();
        $transport2->stop();

        $this->assertEquals(IceTransportState::closed, $transport1->getState());
        $this->assertEquals(IceTransportState::closed, $transport2->getState());
    }

    public function testConnectFail()
    {
        $iceSetting1 = new RTCICESetting;
        $iceSetting1->setIceRole(IceRole::Controlling);
        $gatherer1 = new RTCIceGatherer([], $iceSetting1);
        $transport1 = new RTCIceTransport($gatherer1);

        $iceSetting2 = new RTCICESetting;
        $iceSetting2->setIceRole(IceRole::Controlled);
        $gatherer2 = new RTCIceGatherer([], $iceSetting2);
        $transport2 = new RTCIceTransport($gatherer2);

        $gatherer1->gather();
        $gatherer2->gather();

        foreach ($gatherer2->getLocalCandidates() as $candidate) {
            $transport1->addRemoteCandidate($candidate);
        }
        foreach ($gatherer1->getLocalCandidates() as $candidate) {
            $transport2->addRemoteCandidate($candidate);
        }

        $this->assertEquals(IceTransportState::new, $transport1->getState());
        $this->assertEquals(IceTransportState::new, $transport2->getState());

        $transport2->stop();
        $transport1->start($gatherer2->getLocalParameters());

        $this->assertEquals(IceTransportState::failed, $transport1->getState());
        $this->assertEquals(IceTransportState::closed, $transport2->getState());

        $transport1->stop();

        $this->assertEquals(IceTransportState::closed, $transport1->getState());
        $this->assertEquals(IceTransportState::closed, $transport2->getState());
    }

    public function testConnectThenConsentExpires()
    {
        $iceSetting1 = new RTCICESetting;
        $iceSetting1->setIceRole(IceRole::Controlling);
        $gatherer1 = new RTCIceGatherer([], $iceSetting1);
        $transport1 = new RTCIceTransport($gatherer1);

        $iceSetting2 = new RTCICESetting;
        $iceSetting2->setIceRole(IceRole::Controlled);
        $gatherer2 = new RTCIceGatherer([], $iceSetting2);
        $transport2 = new RTCIceTransport($gatherer2);

        $gatherer1->gather();
        $gatherer2->gather();

        foreach ($gatherer2->getLocalCandidates() as $candidate) {
            $transport1->addRemoteCandidate($candidate);
        }
        foreach ($gatherer1->getLocalCandidates() as $candidate) {
            $transport2->addRemoteCandidate($candidate);
        }

        $this->assertEquals(IceTransportState::new, $transport1->getState());
        $this->assertEquals(IceTransportState::new, $transport2->getState());

        $this->asyncConnect($transport1, $transport2, $gatherer1, $gatherer2);

        $this->assertEquals(IceTransportState::complete, $transport1->getState());
        $this->assertEquals(IceTransportState::complete, $transport2->getState());

        $transport1->stop();
        $this->assertEquals(IceTransportState::closed, $transport1->getState());

        delay(2);
        $transport2->stop();
        $this->assertEquals(IceTransportState::closed, $transport2->getState());
    }

    public function testConnectWhenClosed()
    {
        $gatherer = new RTCIceGatherer([]);
        $transport = new RTCIceTransport($gatherer);

        // Stop transport
        $transport->stop();
        $this->assertEquals(IceTransportState::closed, $transport->getState());

        // Try to start it
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RTCIceTransport is closed');
        $transport->start(new RTCIceParameters(usernameFragment: 'foo', password: 'bar'));
    }

    public function testConnectionClosed()
    {
        $gatherer = new RTCIceGatherer([]);

        $connection = $this->getMockBuilder(RTCIceConnection::class)
            ->setConstructorArgs([new RTCIceProtocolConfiguration()])
            ->onlyMethods(['connect'])
            ->getMock();
        $connectMock = function () use ($connection) {
            async(function () use ($connection) {
                delay(.5);
                $connection->close();
            })->ignore();

            // connect() returns nothing now: it simply comes back once negotiation is done.
        };
        $connection->method('connect')->willReturnCallback($connectMock);

        $gatherer->setIceConnection($connection);
        $transport = new RTCIceTransport($gatherer);

        $this->assertEquals(IceTransportState::new, $transport->getState());

        $transport->start(new RTCIceParameters(usernameFragment: 'foo', password: 'bar'));
        $this->assertEquals(IceTransportState::complete, $transport->getState());

        delay(1);
        $this->assertEquals(IceTransportState::failed, $transport->getState());

        $transport->stop();
        $this->assertEquals(IceTransportState::closed, $transport->getState());
    }

    private function asyncConnect(RTCIceTransport $transport1, RTCIceTransport $transport2, RTCIceGatherer $gatherer1, RTCIceGatherer $gatherer2): void
    {
        // Both transports have to be checking at the same time, so each start() runs in its
        // own fiber and the test waits for both.
        $futures = [
            async(fn() => $transport1->start($gatherer2->getLocalParameters())),
            async(fn() => $transport2->start($gatherer1->getLocalParameters())),
        ];
        foreach ($futures as $future) {
            $future->await();
        }
    }
}
