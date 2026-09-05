<?php

namespace Tests\Webrtc\ICE;

use Amp\Socket\InternetAddress;
use Mockery;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use ReflectionMethod;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;
use Webrtc\ICE\Enum\CandidateType;
use Webrtc\ICE\Enum\IceRole;
use Webrtc\ICE\Enum\RTCIceCandidatePairStats;
use Webrtc\ICE\Enum\TransportPolicyType;
use Webrtc\ICE\Enum\TransportType;
use Webrtc\ICE\RTCIceCandidate;
use Webrtc\ICE\RTCIceCandidatePair;
use Webrtc\ICE\RTCIceConnection;
use Webrtc\ICE\RTCIceProtocolConfiguration;
use Webrtc\ICE\Utils;
use Webrtc\STUN\Enum\MessageAttribute;
use Webrtc\STUN\Enum\MessageClass;
use Webrtc\STUN\Enum\MessageMethod;
use Webrtc\STUN\Exception\TransactionExceptionInterface;
use Webrtc\STUN\Exception\TransactionTimeoutException;
use Webrtc\STUN\IceConnectionProtocolInterface;
use Webrtc\STUN\Message\Message;
use Webrtc\STUN\Message\MessageInterface;
use Webrtc\STUN\StunInterface;
use Webrtc\STUN\Trait\Request;
use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

#[UsesClass(RTCIceProtocolConfiguration::class)]
#[UsesClass(Utils::class)]
#[UsesClass(RTCIceCandidate::class)]
#[UsesClass(RTCIceCandidatePair::class)]
#[CoversClass(RTCIceConnection::class)]
class RTCIceConnectionTest extends TestCase
{
    /** @var resource|null */
    private static mixed $turnServerProcess = null;
    private static ?string $turnServerConfig = null;
    private static ?string $turnServerLog = null;
    private static ?string $turnServerUnavailableReason = null;

    private RTCIceProtocolConfiguration $config;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::turnServerIsReady()) {
            return;
        }

        $binary = self::findTurnServerBinary();
        if ($binary === null) {
            self::$turnServerUnavailableReason = 'The turnserver binary is not installed.';
            return;
        }

        $config = file_get_contents(__DIR__ . '/turnconfig/turnserver.conf');
        if ($config === false) {
            throw new \RuntimeException('Could not read the Coturn test configuration.');
        }

        $turnServerConfig = tempnam(sys_get_temp_dir(), 'php-rtc-coturn-');
        $turnServerLog = tempnam(sys_get_temp_dir(), 'php-rtc-coturn-log-');
        if ($turnServerConfig === false || $turnServerLog === false) {
            throw new \RuntimeException('Could not create temporary Coturn test files.');
        }
        self::$turnServerConfig = $turnServerConfig;
        self::$turnServerLog = $turnServerLog;

        $config = preg_replace(
            ['~^cert=.*$~m', '~^pkey=.*$~m', '~^log-file=.*$~m'],
            [
                'cert=' . __DIR__ . '/turnconfig/turnserver.crt',
                'pkey=' . __DIR__ . '/turnconfig/turnserver.key',
                'log-file=' . self::$turnServerLog,
            ],
            $config,
        );
        if ($config === null || file_put_contents(self::$turnServerConfig, $config) === false) {
            throw new \RuntimeException('Could not write the temporary Coturn test configuration.');
        }

        self::$turnServerProcess = proc_open(
            [$binary, '-c', self::$turnServerConfig],
            [
                0 => ['pipe', 'r'],
                1 => ['file', self::$turnServerLog, 'a'],
                2 => ['file', self::$turnServerLog, 'a'],
            ],
            $pipes,
            dirname(__DIR__),
        );
        if (!is_resource(self::$turnServerProcess)) {
            self::removeTurnServerFiles();
            throw new \RuntimeException('Could not start Coturn for the test suite.');
        }

        fclose($pipes[0]);

        $deadline = microtime(true) + 5;
        do {
            if (self::turnServerIsReady()) {
                return;
            }

            $status = proc_get_status(self::$turnServerProcess);
            if (!$status['running']) {
                break;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        $log = self::$turnServerLog === null ? '' : (string) @file_get_contents(self::$turnServerLog);
        self::stopTurnServer();
        throw new \RuntimeException("Coturn did not become ready.\n" . $log);
    }

    public static function tearDownAfterClass(): void
    {
        self::stopTurnServer();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = new RTCIceProtocolConfiguration();
        $this->config->setStunServer([]);
    }

    protected function tearDown(): void
    {

    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetHostAddresses()
    {
        $connectionMock = $this->getMockBuilder(RTCIceConnection::class)
            ->setConstructorArgs([$this->config])
            ->onlyMethods(['getInterfaces'])
            ->getMock();

        $connectionMock->method('getInterfaces')
            ->willReturn(
                [
                    [
                        'unicast' => [
                            ['address' => '1.2.3.4'],
                            ['address' => '2a02:0db8:85a3:0000:0000:8a2e:0370:7334']
                        ]
                    ]
                ]
            );

        // IPv4 only
        $addresses = $connectionMock->getHostAddresses(true, false);
        $this->assertEquals(['1.2.3.4'], $addresses);

        // IPv6 only
        $addresses = $connectionMock->getHostAddresses(false, true);
        $this->assertEquals(['[2a02:0db8:85a3:0000:0000:8a2e:0370:7334]'], $addresses);

        // Both
        $addresses = $connectionMock->getHostAddresses(true, true);
        $this->assertEquals(['1.2.3.4', '[2a02:0db8:85a3:0000:0000:8a2e:0370:7334]'], $addresses);
    }

    public function testClose()
    {
        $connection = new RTCIceConnection($this->config);
        $this->assertFalse($connection->isClosed());

        // Close
        $connection->close();
        $this->assertTrue($connection->isClosed());
    }

    public function testConnect()
    {
        $connection1 = $this->getIceConnection();
        $connection2 = $this->getIceConnection(false);

        $this->inviteAccept($connection1, $connection2);

        $this->assertCandidateTypes($connection1, ['host']);
        $this->assertCandidateTypes($connection2, ['host']);

        $data1 = [];
        $data2 = [];
        $this->getData($connection1, $data1);
        $this->getData($connection2, $data2);

        $candidate = $connection1->getDefaultCandidate(1);
        $this->assertNotNull($candidate);
        $this->assertEquals('host', $candidate->getType()->name);

        $candidate = $connection1->getDefaultCandidate(2);
        $this->assertNull($candidate);

        $this->asyncConnect($connection1, $connection2);

        $connection1->sendData('Hello');
        delay(.1);
        $this->assertEquals('Hello', $data2[0][0]);

        $connection2->sendData('Bye');
        delay(.01);
        $this->assertEquals('Bye', $data1[0][0]);

        $connection1->close();
        $connection2->close();
    }


    public function testConnectEarlyChecks()
    {
        $connection1 = $this->getIceConnection();
        $connection2 = $this->getIceConnection(false);

        $this->inviteAccept($connection1, $connection2);

        $data1 = [];
        $data2 = [];
        $this->getData($connection1, $data1);
        $this->getData($connection2, $data2);

        // Connection 1 starts checking first, so its Binding requests arrive on
        // connection 2 as early checks. Application data is unreliable UDP with no
        // retransmission, so ICE has to finish on both agents before the single
        // datagram is sent, otherwise it is dropped on a cold path (which happens on
        // the macOS runner, though loopback on Linux buffers it and hides the race).
        $connect1 = async(fn() => $connection1->connect());
        delay(1);
        $connect2 = async(fn() => $connection2->connect());
        try {
            await([$connect1, $connect2]);
        } catch (\Throwable $e) {
            fwrite(STDERR, "\n=== EARLYCHECK DIAG: " . $e->getMessage() . " ===\n");
            foreach (['conn1' => $connection1, 'conn2' => $connection2] as $name => $conn) {
                fwrite(STDERR, "$name locals:\n");
                foreach ($conn->getLocalCandidates() as $c) {
                    fwrite(STDERR, "  " . $c->getType()->name . " " . $c->getHost() . ":" . $c->getPort() . "\n");
                }
                fwrite(STDERR, "$name checklist:\n");
                foreach ($conn->getCheckList() as $p) {
                    fwrite(STDERR, "  " . $p . "\n");
                }
            }
            fwrite(STDERR, "=== END DIAG ===\n");
            throw $e;
        }

        $connection1->sendData('Hello');
        $this->waitForData($data2);
        $this->assertEquals('Hello', $data2[0][0]);

        $connection2->sendData('Bye');
        $this->waitForData($data1);
        $this->assertEquals('Bye', $data1[0][0]);

        $connection1->close();
        $connection2->close();
    }

    /**
     *  The sequence is:
     *  - Connection 1 starts connecting immediately, but has no candidates
     *  - Connection 2 receives candidates and connects
     *  - Connection 1 receives candidates, and connection completes
     *
     * @return void
     * @throws RandomException
     * @throws \Throwable
     */
    public function testConnectEarlyChecks2()
    {
        $connection1 = $this->getIceConnection();
        $connection2 = $this->getIceConnection(false);

        $connection1->gatherCandidates();
        $connection2->gatherCandidates();

        $connection1->setRemoteUsername($connection2->getLocalUsername());
        $connection1->setRemotePassword($connection2->getLocalPassword());
        $connection2->setRemoteUsername($connection1->getLocalUsername());
        $connection2->setRemotePassword($connection1->getLocalPassword());

        $data1 = [];
        $data2 = [];
        $this->getData($connection1, $data1);
        $this->getData($connection2, $data2);

        async(fn() => $connection1->connect())->ignore();

        foreach ($connection1->getLocalCandidates() as $candidate) {
            $connection2->addRemoteCandidate($candidate);
        }
        async(fn() => $connection2->connect())->ignore();


        foreach ($connection2->getLocalCandidates() as $candidate) {
            $connection1->addRemoteCandidate($candidate);
        }

        delay(.1);

        $connection1->sendData('Hello');
        delay(.01);
        $this->assertEquals('Hello', $data2[0][0]);

        $connection2->sendData('Bye');
        delay(.01);
        $this->assertEquals('Bye', $data1[0][0]);

        $connection1->close();
        $connection2->close();
    }

    public function testConnectTwoComponents()
    {
        $connection1 = $this->getIceConnection();
        $connection2 = $this->getIceConnection(false);

        $connection1->setComponentIds(range(1, 2));
        $connection2->setComponentIds(range(1, 2));

        $this->inviteAccept($connection1, $connection2);

        $this->assertCandidateTypes($connection1, ['host']);
        $this->assertCandidateTypes($connection2, ['host']);

        $data1 = [];
        $data2 = [];
        $this->getData($connection1, $data1);
        $this->getData($connection2, $data2);

        $candidate = $connection1->getDefaultCandidate(1);
        $this->assertNotNull($candidate);
        $this->assertEquals('host', $candidate->getType()->name);

        $candidate = $connection1->getDefaultCandidate(2);
        $this->assertNotNull($candidate);

        $this->asyncConnect($connection1, $connection2);

        $this->assertEquals([1, 2], $connection1->getComponentIds());
        $this->assertEquals([1, 2], $connection2->getComponentIds());

        $connection1->sendData('Hello 1');
        delay(.01);
        $this->assertEquals('Hello 1', $data2[0][0]);
        $this->assertEquals(1, $data2[0][1]);

        $connection2->sendData('Bye 1');
        delay(.01);
        $this->assertEquals('Bye 1', $data1[0][0]);
        $this->assertEquals(1, $data1[0][1]);

        $connection1->sendData('Hello 2', 2);
        delay(.01);
        $this->assertEquals('Hello 2', $data2[1][0]);
        $this->assertEquals(2, $data2[1][1]);

        $connection2->sendData('Bye 2', 2);
        delay(.01);
        $this->assertEquals('Bye 2', $data1[1][0]);
        $this->assertEquals(2, $data1[1][1]);

        $connection1->close();
        $connection2->close();
    }

    public function testConnectTwoComponentsVsOneComponent()
    {
        $connection1 = $this->getIceConnection();
        $connection2 = $this->getIceConnection(false);

        $connection1->setComponentIds(range(1, 2));

        $this->inviteAccept($connection1, $connection2);

        $this->assertCandidateTypes($connection1, ['host']);
        $this->assertCandidateTypes($connection2, ['host']);

        $data1 = [];
        $data2 = [];
        $this->getData($connection1, $data1);
        $this->getData($connection2, $data2);

        $candidate = $connection1->getDefaultCandidate(1);
        $this->assertNotNull($candidate);
        $this->assertEquals('host', $candidate->getType()->name);

        $candidate = $connection1->getDefaultCandidate(2);
        $this->assertNotNull($candidate);

        $this->asyncConnect($connection1, $connection2);

        $this->assertEquals([1], $connection1->getComponentIds());
        $this->assertEquals([1], $connection2->getComponentIds());

        $connection1->sendData('Hello');
        delay(.01);
        $this->assertEquals('Hello', $data2[0][0]);
        $this->assertEquals(1, $data2[0][1]);

        $connection2->sendData('Bye');
        delay(.01);
        $this->assertEquals('Bye', $data1[0][0]);

        $connection1->close();
        $connection2->close();
    }

    public function testConnectToIceLite()
    {
        $connection1 = $this->getIceConnection();
        $connection1->setRemoteIsLite(true);
        $connection2 = $this->getIceConnection(false);

        $this->inviteAccept($connection1, $connection2);

        $this->assertCandidateTypes($connection1, ['host']);
        $this->assertCandidateTypes($connection2, ['host']);

        $candidate = $connection1->getDefaultCandidate(1);
        $this->assertNotNull($candidate);
        $this->assertEquals('host', $candidate->getType()->name);

        $data1 = [];
        $data2 = [];
        $this->getData($connection1, $data1);
        $this->getData($connection2, $data2);

        $candidate = $connection1->getDefaultCandidate(2);
        $this->assertNull($candidate);

        $this->asyncConnect($connection1, $connection2);

        $connection1->sendData('Hello');
        delay(.01);
        $this->assertEquals('Hello', $data2[0][0]);

        $connection2->sendData('Bye');
        delay(.01);
        $this->assertEquals('Bye', $data1[0][0]);

        $connection1->close();
        $connection2->close();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConnectToIceLiteNominationFails()
    {
        $connection1 = $this->getIceConnection();
        $connection1->setRemoteIsLite(true);
        $connection2 = $this->getMockBuilder(RTCIceConnection::class)
            ->setConstructorArgs([$this->config])
            ->onlyMethods(['onRequestReceived'])
            ->getMock();

        $onRequestReceivedMock = function (MessageInterface $message, InternetAddress $address, IceConnectionProtocolInterface $protocol, string $data) use ($connection2) {
            if ($message->attributes()->has(MessageAttribute::USE_CANDIDATE)) {
                $connection2->respondError($message, $address, $protocol, [500, 'Internal Error']);
            } else {
                $refMethod = new ReflectionMethod(RTCIceConnection::class, 'onRequestReceived');
                $refMethod->invoke($connection2, $message, $address, $protocol, $data);
            }
        };
        $connection2->method('onRequestReceived')->willReturnCallback($onRequestReceivedMock);

        $this->inviteAccept($connection1, $connection2);

        async(function () use ($connection1, $connection2) {
            delay(5);
            $connection1->close();
            $connection2->close();
        })->ignore();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ICE negotiation failed');
        $this->asyncConnect($connection1, $connection2);
    }

    public function testConnectIpv6()
    {
        if (!$this->isSupportIPv6() || getenv('CI')) {
            $this->markTestSkipped(getenv('CI') ? 'CI lacks IPv6.' : 'This host has no usable IPv6.');
        }

        $connection1 = $this->getIceConnection();
        $connection2 = $this->getIceConnection(false);
        $connection1->setUseIPv4(false);
        $connection2->setUseIPv4(false);

        $this->inviteAccept($connection1, $connection2);
        $this->assertGreaterThan(0, count($connection1->getLocalCandidates()));
        foreach ($connection1->getLocalCandidates() as $candidate) {
            $this->assertEquals('host', $candidate->getType()->name);
        }

        $data1 = [];
        $data2 = [];
        $this->getData($connection1, $data1);
        $this->getData($connection2, $data2);

        $this->asyncConnect($connection1, $connection2);

        $connection1->sendData('Hello');
        delay(.01);
        $this->assertEquals('Hello', $data2[0][0]);

        $connection2->sendData('Bye');
        delay(.01);
        $this->assertEquals('Bye', $data1[0][0]);

        $connection1->close();
        $connection2->close();
    }

    public function testConnectReverseOrder()
    {
        $connection1 = $this->getIceConnection();
        $connection2 = $this->getIceConnection(false);

        $this->inviteAccept($connection1, $connection2);

        async(function () use ($connection1) {
            delay(1);
            async(fn() => $connection1->connect())->ignore();
        })->ignore();
        async(fn() => $connection2->connect())->ignore();

        delay(2);

        $data1 = [];
        $data2 = [];
        $this->getData($connection1, $data1);
        $this->getData($connection2, $data2);

        $connection1->sendData('Hello');
        delay(.01);
        $this->assertEquals('Hello', $data2[0][0]);

        $connection2->sendData('Bye');
        delay(.01);
        $this->assertEquals('Bye', $data1[0][0]);

        $connection1->close();
        $connection2->close();
    }

    public function testConnectInvalidPassword()
    {
        $connection1 = $this->getIceConnection();
        $connection2 = $this->getIceConnection(false);

        $connection1->gatherCandidates();
        foreach ($connection1->getLocalCandidates() as $candidate) {
            $connection2->addRemoteCandidate($candidate);
        }
        $connection2->endOfRemoteCandidate();
        $connection2->setRemoteUsername($connection1->getLocalUsername());
        $connection2->setRemotePassword($connection1->getLocalPassword());

        $connection2->gatherCandidates();
        foreach ($connection2->getLocalCandidates() as $candidate) {
            $connection1->addRemoteCandidate($candidate);
        }
        $connection1->endOfRemoteCandidate();
        $connection1->setRemoteUsername($connection2->getLocalUsername());
        $connection1->setRemotePassword('wrong-password');

        async(function () use ($connection1, $connection2) {
            delay(1);
            $connection1->close();
            $connection2->close();
        })->ignore();

        $this->expectException(RuntimeException::class);
        $this->asyncConnect($connection1, $connection2);
    }

    public function testConnectInvalidUsername()
    {
        $connection1 = $this->getIceConnection();
        $connection2 = $this->getIceConnection(false);

        $connection1->gatherCandidates();
        foreach ($connection1->getLocalCandidates() as $candidate) {
            $connection2->addRemoteCandidate($candidate);
        }
        $connection2->endOfRemoteCandidate();
        $connection2->setRemoteUsername($connection1->getLocalUsername());
        $connection2->setRemotePassword($connection1->getLocalPassword());

        $connection2->gatherCandidates();
        foreach ($connection2->getLocalCandidates() as $candidate) {
            $connection1->addRemoteCandidate($candidate);
        }
        $connection1->endOfRemoteCandidate();
        $connection1->setRemoteUsername('wrong-username');
        $connection1->setRemotePassword($connection2->getLocalPassword());

        async(function () use ($connection1, $connection2) {
            delay(1);
            $connection1->close();
            $connection2->close();
        })->ignore();

        $this->expectException(RuntimeException::class);
        $this->asyncConnect($connection1, $connection2);
    }

    public function testConnectNoGather()
    {
        $connection = $this->getIceConnection();
        $connection->addRemoteCandidate(RTCIceCandidate::parseSDP('6815297761 1 udp 659136 1.2.3.4 31102 typ host generation 0'));
        $connection->endOfRemoteCandidate();
        $connection->setRemoteUsername('foo');
        $connection->setRemotePassword('bar');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Local candidates gathering was not performed');
        $connection->connect();

        $connection->close();
    }

    public function testConnectNoLocalCandidates()
    {
        $connection = $this->getIceConnection();
        $connection->setRemoteCandidatesEnd(true);
        $this->expectException(InvalidArgumentException::class);
        $connection->addRemoteCandidate(RTCIceCandidate::parseSDP('6815297761 1 udp 659136 1.2.3.4 31102 typ host generation 0'));
        $connection->endOfRemoteCandidate();
        $connection->setRemoteUsername('foo');
        $connection->setRemotePassword('bar');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ICE negotiation failed');
        $connection->connect();

        $connection->close();
    }

    public function testConnectNoRemoteCandidates()
    {
        $connection = $this->getIceConnection();
        $connection->gatherCandidates();
        $connection->endOfRemoteCandidate();
        $connection->setRemoteUsername('foo');
        $connection->setRemotePassword('bar');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ICE negotiation failed: Binding check failed');
        $connection->connect();

        $connection->close();
    }

    public function testConnectNoRemoteCredentials()
    {
        $connection = $this->getIceConnection();
        $connection->gatherCandidates();
        $connection->addRemoteCandidate(RTCIceCandidate::parseSDP('6815297761 1 udp 659136 1.2.3.4 31102 typ host generation 0'));
        $connection->endOfRemoteCandidate();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Remote username or password is missing');
        $connection->connect();

        $connection->close();
    }

    public function testConnectRoleConflictBothControlling()
    {
        $connection1 = $this->getIceConnection();
        $connection2 = $this->getIceConnection();

        $connection1->setTieBreaker(1);
        $connection2->setTieBreaker(2);

        $this->inviteAccept($connection1, $connection2);

        $this->asyncConnect($connection1, $connection2);

        $this->assertFalse($connection1->isControllingRole());
        $this->assertTrue($connection2->isControllingRole());

        $connection1->close();
        $connection2->close();
    }

    public function testConnectRoleConflictBothControlled()
    {
        $connection1 = $this->getIceConnection(false);
        $connection2 = $this->getIceConnection(false);

        $connection1->setTieBreaker(1);
        $connection2->setTieBreaker(2);

        $this->inviteAccept($connection1, $connection2);

        $this->asyncConnect($connection1, $connection2);

        $this->assertFalse($connection1->isControllingRole());
        $this->assertTrue($connection2->isControllingRole());

        $connection1->close();
        $connection2->close();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConnectTimeout()
    {
        $connection = $this->getMockBuilder(RTCIceConnection::class)
            ->setConstructorArgs([$this->config, IceRole::Controlling])
            ->onlyMethods(['startCheckBinding'])
            ->getMock();
        $startCheckBindingMock = function (RTCIceCandidatePair $pair) use ($connection) {
            $connection->changeCandidatePairState($pair, RTCIceCandidatePairStats::IN_PROGRESS);
            $nominate = $connection->isControllingRole();
            $message = $connection->buildBindingMessage($pair, $nominate);
            $remoteAddress = $pair->getRemoteAddress();

            // Mirrors the real startCheckBinding(): the request blocks, so it runs in its
            // own fiber and the check list keeps moving while it is outstanding.
            async(function () use ($connection, $pair, $message, $remoteAddress, $nominate): void {
                try {
                    [, $address] = $pair->getProtocol()->request($message, $remoteAddress, $connection->getRemotePassword(), -1);
                    $connection->handleCheckBinding($address, $pair, $nominate);
                } catch (TransactionExceptionInterface $e) {
                    $connection->handleBindingError($e, $pair, $message);
                }
            })->ignore();
        };
        $connection->method('startCheckBinding')->willReturnCallback($startCheckBindingMock);

        $connection->gatherCandidates();
        $connection->addRemoteCandidate(RTCIceCandidate::parseSDP('6815297761 1 udp 659136 1.2.3.4 31102 typ host generation 0'));
        $connection->setRemoteCandidatesEnd(true);
        $connection->setRemoteUsername('foo');
        $connection->setRemotePassword('bar');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ICE negotiation failed');
        $connection->connect();

        $connection->close();
    }

    public function testConnectWithStunServer()
    {
        $this->requireLocalTurnServer();

        $config = clone $this->config;
        $config->setStunServer(['127.0.0.1', 3478]);
        $connection1 = new RTCIceConnection($config, IceRole::Controlling);
        $connection2 = $this->getIceConnection(false);

        $this->inviteAccept($connection1, $connection2);

        $this->assertCandidateTypes($connection1, ['host', 'srflx']);
        $this->assertCandidateTypes($connection2, ['host']);

        $candidate = $connection1->getDefaultCandidate(1);
        $this->assertNotNull($candidate);
        $this->assertEquals('srflx', $candidate->getType()->name);
        $this->assertNotNull($candidate->getRelatedAddress());
        $this->assertNotNull($candidate->getRelatedPort());

        $this->asyncConnect($connection1, $connection2);

        $data1 = [];
        $data2 = [];
        $this->getData($connection1, $data1);
        $this->getData($connection2, $data2);

        $connection1->sendData('Hello');
        delay(.01);
        $this->assertEquals('Hello', $data2[0][0]);

        $connection2->sendData('Bye');
        delay(.01);
        $this->assertEquals('Bye', $data1[0][0]);

        $connection1->close();
        $connection2->close();
    }

    public function testConnectWithStunServerDnsLookupError()
    {
        // This test relies on STUN gathering failing during an ICE negotiation; the shared
        // GitHub Actions runners resolve and route traffic in ways that break the negotiation,
        // so only run it off CI.
        if (getenv('CI')) {
            $this->markTestSkipped('Got conflict on GitHub Actions.');
        }

        // RFC 2606 reserves .test so that it never resolves, but a resolver that answers
        // wildcards would hand back an address and there would be no lookup failure to see.
        if (gethostbyname('fakestun.test') !== 'fakestun.test') {
            $this->markTestSkipped('This resolver answers for names that should not exist.');
        }

        $config = clone $this->config;
        $config->setStunServer(['fakestun.test', 3478]); // invalid stun server domain name
        $connection1 = new RTCIceConnection($config, IceRole::Controlling);
        $connection2 = $this->getIceConnection(false);

        $this->inviteAccept($connection1, $connection2);

        $this->assertCandidateTypes($connection1, ['host']);
        $this->assertCandidateTypes($connection2, ['host']);

        $this->asyncConnect($connection1, $connection2);

        $data1 = [];
        $data2 = [];
        $this->getData($connection1, $data1);
        $this->getData($connection2, $data2);

        $connection1->sendData('Hello');
        delay(.01);
        $this->assertEquals('Hello', $data2[0][0]);

        $connection2->sendData('Bye');
        delay(.01);
        $this->assertEquals('Bye', $data1[0][0]);

        $connection1->close();
        $connection2->close();
    }

    public function testConnectWithStunServerTimeout()
    {
        // Same CI conflict as the DNS lookup error: gathering depends on the STUN
        // request timing out while the connection stays up, which is not reliable
        // on shared GitHub Actions runners.
        if (getenv('CI')) {
            $this->markTestSkipped('Got conflict on GitHub Actions.');
        }

        // The point is that nothing answers, so a STUN server that happens to be listening
        // on this port would make the request succeed rather than time out.
        if (self::portIsOccupied(1234)) {
            $this->markTestSkipped('Something is listening on 127.0.0.1:1234.');
        }

        $config = clone $this->config;
        $config->setStunServer(['127.0.0.1', 1234]); // Invalid port causes timout
        $connection1 = new RTCIceConnection($config, IceRole::Controlling);
        $connection2 = $this->getIceConnection(false);

        $this->inviteAccept($connection1, $connection2);

        $this->assertCandidateTypes($connection1, ['host']);
        $this->assertCandidateTypes($connection2, ['host']);

        $this->asyncConnect($connection1, $connection2);

        $data1 = [];
        $data2 = [];
        $this->getData($connection1, $data1);
        $this->getData($connection2, $data2);

        $connection1->sendData('Hello');
        delay(.01);
        $this->assertEquals('Hello', $data2[0][0]);

        $connection2->sendData('Bye');
        delay(.01);
        $this->assertEquals('Bye', $data1[0][0]);

        $connection1->close();
        $connection2->close();
    }

    public function testConnectWithStunServerIpv6()
    {
        if (!$this->isSupportIPv6() || getenv('CI')) {
            $this->markTestSkipped(getenv('CI') ? 'CI lacks IPv6.' : 'This host has no usable IPv6.');
        }

        $config = clone $this->config;
        $config->setStunServer(['127.0.0.1', 3478]);
        $connection1 = new RTCIceConnection($config, IceRole::Controlling);
        $connection2 = $this->getIceConnection(false);
        $connection1->setUseIPv4(false);
        $connection2->setUseIPv4(false);

        $this->inviteAccept($connection1, $connection2);

        $this->assertGreaterThan(0, count($connection1->getLocalCandidates()));
        foreach ($connection1->getRemoteCandidates() as $candidate) {
            $this->assertEquals('host', $candidate->getType()->name);
        }

        $this->asyncConnect($connection1, $connection2);

        $data1 = [];
        $data2 = [];
        $this->getData($connection1, $data1);
        $this->getData($connection2, $data2);

        $connection1->sendData('Hello');
        delay(.01);
        $this->assertEquals('Hello', $data2[0][0]);

        $connection2->sendData('Bye');
        delay(.01);
        $this->assertEquals('Bye', $data1[0][0]);

        $connection1->close();
        $connection2->close();
    }

    public function testConnectWithTurnServerTcp()
    {
        $this->requireLocalTurnServer();

        $config = clone $this->config;
        $config->setTurnServer(['127.0.0.1', 3478]);
        $config->setTurnUsername('quasarstream');
        $config->setTurnPassword('123');
        $config->setTurnTransport('tcp');
        $connection1 = new RTCIceConnection($config, IceRole::Controlling);
        $connection2 = $this->getIceConnection(false);

        $this->inviteAccept($connection1, $connection2);

        $this->assertCandidateTypes($connection1, ['host', 'relay']);
        $this->assertCandidateTypes($connection2, ['host']);

        $candidate = $connection1->getDefaultCandidate(1);
        $this->assertNotNull($candidate);
        $this->assertEquals('relay', $candidate->getType()->name);
        $this->assertNotNull($candidate->getRelatedAddress());
        $this->assertNotNull($candidate->getRelatedPort());

        $this->asyncConnect($connection1, $connection2);

        $data1 = [];
        $data2 = [];
        $this->getData($connection1, $data1);
        $this->getData($connection2, $data2);

        $connection1->sendData('Hello');
        delay(.01);
        $this->assertEquals('Hello', $data2[0][0]);

        $connection2->sendData('Bye');
        delay(.01);
        $this->assertEquals('Bye', $data1[0][0]);

        $connection1->close();
        $connection2->close();
    }

    public function testConnectWithTurnServerUdp()
    {
        $this->requireLocalTurnServer();

        $config = clone $this->config;
        $config->setTurnServer(['127.0.0.1', 3478]);
        $config->setTurnUsername('quasarstream');
        $config->setTurnPassword('123');
        $connection1 = new RTCIceConnection($config, IceRole::Controlling);
        $connection2 = $this->getIceConnection(false);

        $this->inviteAccept($connection1, $connection2);

        $this->assertCandidateTypes($connection1, ['host', 'relay']);
        $this->assertCandidateTypes($connection2, ['host']);

        $candidate = $connection1->getDefaultCandidate(1);
        $this->assertNotNull($candidate);
        $this->assertEquals('relay', $candidate->getType()->name);
        $this->assertNotNull($candidate->getRelatedAddress());
        $this->assertNotNull($candidate->getRelatedPort());

        $this->asyncConnect($connection1, $connection2);

        $data1 = [];
        $data2 = [];
        $this->getData($connection1, $data1);
        $this->getData($connection2, $data2);

        $connection1->sendData('Hello');
        delay(.01);
        $this->assertEquals('Hello', $data2[0][0]);

        $connection2->sendData('Bye');
        delay(.01);
        $this->assertEquals('Bye', $data1[0][0]);

        $connection1->close();
        $connection2->close();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsentExpired()
    {
        $connection1 = $this->getMockBuilder(RTCIceConnection::class)
            ->setConstructorArgs([$this->config, IceRole::Controlling])
            ->onlyMethods(['periodicConsentCheck'])
            ->getMock();

        $periodicConsentCheckMock = function () use ($connection1) {
            $failureCount = 0;

            $queryConsentTimer = \Revolt\EventLoop::repeat(1, function () use (&$failureCount, $connection1): void {
                foreach ($connection1->getNominated() as $pair) {
                    $message = $connection1->buildBindingMessage($pair, false);
                    $remoteAddress = $pair->getRemoteAddress();

                    // Mirrors periodicConsentCheck(): the request blocks, so it runs in its
                    // own fiber and the timer callback stays free to fire again.
                    async(function () use ($pair, $message, $remoteAddress, $connection1, &$failureCount): void {
                        try {
                            $pair->getProtocol()->request($message, $remoteAddress, $connection1->getRemotePassword());
                            $failureCount = 0; // Reset failures on success
                        } catch (\Throwable) {
                            $failureCount++;
                            if ($failureCount >= 1) {
                                $connection1->close();
                            }
                        }
                    })->ignore();

                }
            });
            $connection1->setQueryConsentTimer($queryConsentTimer);
        };
        $connection1->method('periodicConsentCheck')->willReturnCallback($periodicConsentCheckMock);

        $connection2 = $this->getIceConnection(false);

        $this->inviteAccept($connection1, $connection2);

        $this->asyncConnect($connection1, $connection2);
        $this->assertCount(1, $connection1->getNominated());

        $connection2->close();
        delay(2);
        $this->assertCount(0, $connection1->getNominated());

        $connection1->close();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsentValid()
    {
        $connection1 = $this->getMockBuilder(RTCIceConnection::class)
            ->setConstructorArgs([$this->config, IceRole::Controlling])
            ->onlyMethods(['periodicConsentCheck'])
            ->getMock();

        $periodicConsentCheckMock = function () use ($connection1) {
            $failureCount = 0;

            $queryConsentTimer = \Revolt\EventLoop::repeat(1, function () use (&$failureCount, $connection1): void {
                foreach ($connection1->getNominated() as $pair) {
                    $message = $connection1->buildBindingMessage($pair, false);
                    $remoteAddress = $pair->getRemoteAddress();

                    // Mirrors periodicConsentCheck(): the request blocks, so it runs in its
                    // own fiber and the timer callback stays free to fire again.
                    async(function () use ($pair, $message, $remoteAddress, $connection1, &$failureCount): void {
                        try {
                            $pair->getProtocol()->request($message, $remoteAddress, $connection1->getRemotePassword());
                            $failureCount = 0; // Reset failures on success
                        } catch (\Throwable) {
                            $failureCount++;
                            if ($failureCount >= 1) {
                                $connection1->close();
                            }
                        }
                    })->ignore();

                }
            });
            $connection1->setQueryConsentTimer($queryConsentTimer);
        };
        $connection1->method('periodicConsentCheck')->willReturnCallback($periodicConsentCheckMock);

        $connection2 = $this->getIceConnection(false);

        $this->inviteAccept($connection1, $connection2);

        $this->asyncConnect($connection1, $connection2);
        $this->assertCount(1, $connection1->getNominated());

        delay(2);
        $this->assertCount(1, $connection1->getNominated());

        $connection1->close();
        $connection2->close();
    }

    //FIXME
//    public function testSendNotConnected()
//    {
//        $connection = $this->getIceConnection();
//
//        $this->expectException(RuntimeException::class);
//        $this->expectExceptionMessage('No Connection');
//        $connection->sendData('Hello');
//    }

    public function testAddRemoteCandidate()
    {
        $connection = $this->getIceConnection();

        $remoteCandidate = new RTCIceCandidate(1);
        $remoteCandidate->setFoundation('foundation');
        $remoteCandidate->setTransport(TransportType::udp);
        $remoteCandidate->setPriority(123456);
        $remoteCandidate->setHost('1.2.3.4');
        $remoteCandidate->setPort(4321);
        $remoteCandidate->setType(CandidateType::host);

        $connection->addRemoteCandidate($remoteCandidate);
        $this->assertCount(1, $connection->getRemoteCandidates());
        $this->assertEquals('1.2.3.4', $connection->getRemoteCandidates()[0]->getHost());
        $this->assertFalse($connection->isRemoteCandidatesEnd());

        $connection->endOfRemoteCandidate();
        $this->assertCount(1, $connection->getRemoteCandidates());
        $this->assertTrue($connection->isRemoteCandidatesEnd());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to add remote candidate after the end-of-candidates stage.');
        $connection->addRemoteCandidate($remoteCandidate);
        $this->assertCount(1, $connection->getRemoteCandidates());
        $this->assertTrue($connection->isRemoteCandidatesEnd());
    }

    public function testAddRemoteCandidateMdnsBad()
    {
        $mdnsMock = new MdnsServerMock(['test.local' => '192.168.1.20']);
        $mdnsMock->start();

        $connection = $this->getIceConnection();

        $remoteCandidate = new RTCIceCandidate(1);
        $remoteCandidate->setFoundation('foundation');
        $remoteCandidate->setTransport(TransportType::udp);
        $remoteCandidate->setPriority(123456);
        $remoteCandidate->setHost('test-wrong.local');
        $remoteCandidate->setPort(4321);
        $remoteCandidate->setType(CandidateType::host);

        $connection->addRemoteCandidate($remoteCandidate);
        $this->assertCount(0, $connection->getRemoteCandidates());
        $this->assertFalse($connection->isRemoteCandidatesEnd());

        $connection->close();
        $mdnsMock->stop();
    }

    public function testAddRemoteCandidateMdnsGood()
    {
        if (!Multicast::isAvailable()) {
            $this->markTestSkipped(Multicast::skipReason());
        }

        $mdnsMock = new MdnsServerMock(['test.local' => '192.168.1.20']);
        $mdnsMock->start();

        $connection = $this->getIceConnection();

        $remoteCandidate = new RTCIceCandidate(1);
        $remoteCandidate->setFoundation('foundation');
        $remoteCandidate->setTransport(TransportType::udp);
        $remoteCandidate->setPriority(123456);
        $remoteCandidate->setHost('test.local');
        $remoteCandidate->setPort(1234);
        $remoteCandidate->setType(CandidateType::host);

        $connection->addRemoteCandidate($remoteCandidate);

        $this->assertCount(1, $connection->getRemoteCandidates());
        $this->assertEquals('192.168.1.20', $connection->getRemoteCandidates()[0]->getHost());
        $this->assertFalse($connection->isRemoteCandidatesEnd());

        $connection->close();
        $mdnsMock->stop();
    }

    public function testGatherCandidatesRelayOnlyNoServers()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Relay transport policy requires a STUN and/or TURN server.');
        $connection = $this->getIceConnection();
        $connection->setTransportPolicy(TransportPolicyType::RELAY);
    }

    public function testGatherCandidatesRelayOnlyWithStunServer()
    {
        $this->requireLocalTurnServer();

        $config = clone $this->config;
        $config->setStunServer(['127.0.0.1', 3478]);
        $connection1 = new RTCIceConnection($config, IceRole::Controlling);
        $connection1->setTransportPolicy(TransportPolicyType::RELAY);
        $connection2 = $this->getIceConnection(false);

        $this->inviteAccept($connection1, $connection2);

        $this->assertCandidateTypes($connection1, ['srflx']);
        $this->assertCandidateTypes($connection2, ['host']);

        $candidate = $connection1->getDefaultCandidate(1);
        $this->assertNotNull($candidate);
        $this->assertEquals('srflx', $candidate->getType()->name);
        $this->assertNotNull($candidate->getRelatedAddress());
        $this->assertNotNull($candidate->getRelatedPort());

        $this->asyncConnect($connection1, $connection2);

        $data1 = [];
        $data2 = [];
        $this->getData($connection1, $data1);
        $this->getData($connection2, $data2);

        $connection1->sendData('Hello');
        delay(.01);
        $this->assertEquals('Hello', $data2[0][0]);

        $connection2->sendData('Bye');
        delay(.01);
        $this->assertEquals('Bye', $data1[0][0]);

        $connection1->close();
        $connection2->close();
    }

    public function testGatherCandidatesRelayOnlyWithTurnServer()
    {
        $this->requireLocalTurnServer();

        $config = clone $this->config;
        $config->setTurnServer(['127.0.0.1', 3478]);
        $config->setTurnUsername('quasarstream');
        $config->setTurnPassword('123');
        $connection = new RTCIceConnection($config, IceRole::Controlling);
        $connection->setTransportPolicy(TransportPolicyType::RELAY);

        $connection->gatherCandidates();
        $this->assertCandidateTypes($connection, ['relay']);

        $candidate = $connection->getDefaultCandidate(1);
        $this->assertNotNull($candidate);
        $this->assertEquals('relay', $candidate->getType()->name);
        $this->assertNotNull($candidate->getRelatedAddress());
        $this->assertNotNull($candidate->getRelatedPort());

        // It must have a pair to connect and round-trip data
        $connection->close();
    }

    public function testPeerReflexive()
    {
        $connection = $this->getIceConnection();
        $connection->setRemoteUsername('username');
        $connection->setRemotePassword('password');

        $candidateMock = Mockery::mock(RTCIceCandidate::class);
        $candidateMock->shouldReceive('getComponentId')->once()->andReturn(1);
        $candidateMock->shouldReceive('getPriority')->once()->andReturn(1234596);

        $protocolMock = Mockery::mock(StunInterface::class);
        $protocolMock->shouldReceive('getCandidate')->andReturn($candidateMock);
        $protocolMock->shouldReceive('request')->andReturnUsing(function (...$args) {
            // request() blocks and throws now, rather than handing back a rejected promise.
            throw new TransactionTimeoutException();
        });

        $messageAttr = [
            MessageAttribute::PRIORITY->name => 987520
        ];
        $message = Message::new(MessageClass::REQUEST, MessageMethod::BINDING, $messageAttr);

        $connection->checkIncoming($message, new InternetAddress("127.0.0.0", 1234), $protocolMock);

        $this->assertCount(1, $connection->getRemoteCandidates());
        $candidate = $connection->getRemoteCandidates()[0];
        $this->assertEquals(1, $candidate->getComponentId());
        $this->assertEquals('udp', $candidate->getTransport()->name);
        $this->assertEquals(987520, $candidate->getPriority());
        $this->assertEquals('127.0.0.0', $candidate->getHost());
        $this->assertEquals(1234, $candidate->getPort());
        $this->assertEquals('prflx', $candidate->getType()->name);
        $this->assertNull($candidate->getGeneration());

        $this->assertCount(1, $connection->getCheckList());
        $pair = $connection->getCheckList()[0];
        $this->assertEquals($protocolMock, $pair->getProtocol());
        $this->assertEquals($candidate, $pair->getRemoteCandidate());
    }

    public function testRequestWithInvalidMethod()
    {
        $connection = $this->getIceConnection();

        $messages = [];
        $protocolMock = Mockery::mock(StunInterface::class);
        $protocolMock->shouldReceive('sendMessage')->andReturnUsing(function (...$args) use (&$messages) {
            $messages[] = $args[0];
        });

        $message = Message::new(MessageClass::REQUEST, MessageMethod::ALLOCATE);

        $connection->onRequestReceived($message, new InternetAddress("127.0.0.1", 1234), $protocolMock, (string)$message);
        $this->assertCount(1, $messages);
        $this->assertEquals(MessageMethod::ALLOCATE, $messages[0]->getMessageMethod());
        $this->assertEquals(MessageClass::ERROR, $messages[0]->getMessageClass());
        $this->assertEquals([400, 'Bad Request'], $messages[0]->attributes()->get(MessageAttribute::ERROR_CODE));
    }

    public function testResponseWithInvalidAddress()
    {
        $connection = $this->getIceConnection();
        $connection->setRemoteUsername('username');
        $connection->setRemotePassword('password');

        $candidateMock = Mockery::mock(RTCIceCandidate::class);
        $candidateMock->shouldReceive('getHost')->once()->andReturn("127.0.0.1");
        $candidateMock->shouldReceive('getPort')->once()->andReturn(1234);
        $candidateMock->shouldReceive('getPriority')->once()->andReturn(564123);

        $protocolMock = Mockery::mock(StunInterface::class);
        $protocolMock->shouldReceive('getCandidate')->andReturn($candidateMock);
        $protocolMock->shouldReceive('request')->andReturnUsing(function (...$args) {
            // request() blocks and throws now, rather than handing back a rejected promise.
            throw new TransactionTimeoutException();
        });

        $remoteCandidate = new RTCIceCandidate(1);
        $remoteCandidate->setFoundation('foundation');
        $remoteCandidate->setTransport(TransportType::udp);
        $remoteCandidate->setPriority(123456);
        $remoteCandidate->setHost('192.0.2.1');
        $remoteCandidate->setPort(1234);
        $remoteCandidate->setType(CandidateType::host);


        $pair = new RTCIceCandidatePair($protocolMock, $remoteCandidate);
        $this->assertEquals("CandidatePair(Local Address: 127.0.0.1:1234 -> Remote Address: 192.0.2.1:1234 | State: FROZEN)", (string)$pair);

        $connection->startCheckBinding($pair);

        // The check runs in its own fiber so the check list keeps moving, so the pair does
        // not reach its terminal state until the loop has had a turn.
        delay(.01);

        $this->assertEquals(RTCIceCandidatePairStats::FAILED, $pair->getState());
    }

    /**
     * Whether anything holds a UDP port on loopback.
     */
    private static function portIsOccupied(int $port): bool
    {
        $socket = @stream_socket_server("udp://127.0.0.1:$port", $errno, $errstr, STREAM_SERVER_BIND);

        if ($socket === false) {
            return true;
        }

        fclose($socket);

        return false;
    }

    private static function findTurnServerBinary(): ?string
    {
        // Windows names the executable turnserver.exe; POSIX has no suffix.
        $names = DIRECTORY_SEPARATOR === '\\' ? ['turnserver.exe', 'turnserver'] : ['turnserver'];
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            foreach ($names as $name) {
                $binary = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
                if (is_file($binary) && is_executable($binary)) {
                    return $binary;
                }
            }
        }

        return null;
    }

    private static function turnServerIsReady(): bool
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:3478', $errorCode, $errorMessage, .1);
        if ($socket === false) {
            return false;
        }

        fclose($socket);
        return true;
    }

    private static function stopTurnServer(): void
    {
        if (is_resource(self::$turnServerProcess)) {
            $status = proc_get_status(self::$turnServerProcess);
            if ($status['running']) {
                proc_terminate(self::$turnServerProcess);

                $deadline = microtime(true) + 1;
                do {
                    usleep(10_000);
                    $status = proc_get_status(self::$turnServerProcess);
                } while ($status['running'] && microtime(true) < $deadline);

                if ($status['running']) {
                    proc_terminate(self::$turnServerProcess, 9);
                }
            }

            proc_close(self::$turnServerProcess);
            self::$turnServerProcess = null;
        }

        self::removeTurnServerFiles();
    }

    private static function removeTurnServerFiles(): void
    {
        if (self::$turnServerConfig !== null) {
            @unlink(self::$turnServerConfig);
            self::$turnServerConfig = null;
        }
        if (self::$turnServerLog !== null) {
            @unlink(self::$turnServerLog);
            self::$turnServerLog = null;
        }
    }

    private function requireLocalTurnServer(): void
    {
        if (!self::turnServerIsReady()) {
            $this->markTestSkipped(self::$turnServerUnavailableReason ?? 'The test-managed Coturn server is unavailable.');
        }
    }

    private function assertCandidateTypes(RTCIceConnection $conn, array $expected): void
    {
        $types = array_unique(array_map(fn(RTCIceCandidate $c) => $c->getType()->name, $conn->getLocalCandidates()));
        $this->assertEquals($expected, array_values($types));
    }

    private function getIceConnection(bool $iceControlling = true): RTCIceConnection
    {
        return new RTCIceConnection($this->config, $iceControlling ? IceRole::Controlling : IceRole::Controlled);
    }

    private function inviteAccept(RTCIceConnection $connection1, RTCIceConnection $connection2): void
    {
        // Invite
        $connection1->gatherCandidates();
        foreach ($connection1->getLocalCandidates() as $candidate) {
            $connection2->addRemoteCandidate($candidate);
        }
        $connection2->endOfRemoteCandidate();
        $connection2->setRemoteUsername($connection1->getLocalUsername());
        $connection2->setRemotePassword($connection1->getLocalPassword());

        // Accept
        $connection2->gatherCandidates();
        foreach ($connection2->getLocalCandidates() as $candidate) {
            $connection1->addRemoteCandidate($candidate);
        }
        $connection1->endOfRemoteCandidate();
        $connection1->setRemoteUsername($connection2->getLocalUsername());
        $connection1->setRemotePassword($connection2->getLocalPassword());
    }

    private function getData(RTCIceConnection $iceConnection, array &$data): void
    {
        $iceConnection->on('data', function (...$args) use (&$data) {
            $data [] = $args;
        });
    }

    /**
     * Polls the connection loop until at least one datagram has been received, so
     * delivery checks don't depend on fixed sleeps that are too short on slow CI nodes.
     */
    private function waitForData(array &$data, float $timeout = 5.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (!isset($data[0]) && microtime(true) < $deadline) {
            delay(.01);
        }
    }

    private function isSupportIPv6(): bool
    {
        $socket = @stream_socket_server("tcp://[::1]:0", $errno, $errstr);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private function asyncConnect(RTCIceConnection $connection1, RTCIceConnection $connection2): void
    {
        // Both agents have to be checking at the same time for the exchange to converge, so
        // each connect() runs in its own fiber and the test waits for both.
        $futures = [async(fn() => $connection1->connect()), async(fn() => $connection2->connect())];
        try {
            await($futures);
        } catch (\Throwable $e) {
            foreach ($futures as $future) {
                $future->ignore();
            }
            throw $e;
        }
    }
}
