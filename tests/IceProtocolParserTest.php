<?php

namespace Tests\Webrtc\ICE;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\ICE\IceProtocolParser;
use Webrtc\ICE\RTCIceProtocolConfiguration;
use Webrtc\ICE\RTCIceProtocolConfigurationInterface;
use Webrtc\ICE\RTCIceServer;

#[UsesClass(RTCIceServer::class)]
#[UsesClass(RTCIceProtocolConfiguration::class)]
#[CoversClass(IceProtocolParser::class)]
class IceProtocolParserTest extends TestCase
{
    public function testEmpty()
    {
        $parser = new IceProtocolParser([]);
        $this->assertParser([], $parser->getIceConnectionConfiguration());
    }

    public function testStun()
    {
        $parser = $this->getParser([['urls' => 'stun:stun.l.google.com:19302']]);
        $this->assertParser(
            ['stun_server' => ['stun.l.google.com', 19302]],
            $parser->getIceConnectionConfiguration()
        );
    }

    public function testStunWithSuffix()
    {
        $parser = $this->getParser([['urls' => 'stun:global.stun.twilio.com:3478?transport=udp']]);
        $this->assertParser(
            ['stun_server' => ['global.stun.twilio.com', 3478]],
            $parser->getIceConnectionConfiguration()
        );
    }

    public function testStunMultipleServers()
    {
        $parser = $this->getParser([
            ['urls' => 'stun:stun.l.google.com:19302'],
            ['urls' => 'stun:stun.example.com'],
        ]);

        $this->assertParser(
            ['stun_server' => ['stun.l.google.com', 19302]],
            $parser->getIceConnectionConfiguration()
        );
    }

    public function testStunMultipleUrls()
    {
        $parser = $this->getParser([
            ['urls' => ['stun:stun1.l.google.com:19302', 'stun:stun2.l.google.com:19302']]
        ]);

        $this->assertParser(
            ['stun_server' => ['stun1.l.google.com', 19302]],
            $parser->getIceConnectionConfiguration()
        );
    }

    public function testTurn()
    {
        $parser = $this->getParser([
            ['urls' => 'turn:turn.example.com']
        ]);

        $this->assertParser(
            [
                'turn_password' => null,
                'turn_server' => ['turn.example.com', 3478],
                'turn_ssl' => false,
                'turn_transport' => 'udp',
                'turn_username' => null,
            ],
            $parser->getIceConnectionConfiguration()
        );
    }

    public function testTurnMultipleServers()
    {
        $parser = $this->getParser([
            ['urls' => 'turn:turn.example.com'],
            ['urls' => 'turn:turn.example.net'],
        ]);

        $this->assertParser(
            [
                'turn_password' => null,
                'turn_server' => ['turn.example.com', 3478],
                'turn_ssl' => false,
                'turn_transport' => 'udp',
                'turn_username' => null,
            ],
            $parser->getIceConnectionConfiguration()
        );
    }

    public function testTurnMultipleUrls()
    {
        $parser = $this->getParser([
            ['urls' => ['turn:turn1.example.com', 'turn:turn2.example.com']]
        ]);

        $this->assertParser(
            [
                'turn_password' => null,
                'turn_server' => ['turn1.example.com', 3478],
                'turn_ssl' => false,
                'turn_transport' => 'udp',
                'turn_username' => null,
            ],
            $parser->getIceConnectionConfiguration()
        );
    }

    public function testTurnOverBogus()
    {
        $parser = $this->getParser([
            ['urls' => ['turn:turn.example.com?transport=bogus']]
        ]);

        $this->assertParser(
            [],
            $parser->getIceConnectionConfiguration()
        );
    }

    public function testTurnOverTcp()
    {
        $parser = $this->getParser([
            ['urls' => ['turn:turn.example.com?transport=tcp']]
        ]);

        $this->assertParser(
            [
                'turn_password' => null,
                'turn_server' => ['turn.example.com', 3478],
                'turn_ssl' => false,
                'turn_transport' => 'tcp',
                'turn_username' => null,
            ],
            $parser->getIceConnectionConfiguration()
        );
    }

    public function testTurnWithPassword()
    {
        $parser = $this->getParser([
            ['urls' => 'turn:turn.example.com', 'username' => 'foo', 'password' => 'bar'],
        ]);

        $this->assertParser(
            [
                'turn_password' => 'bar',
                'turn_server' => ['turn.example.com', 3478],
                'turn_ssl' => false,
                'turn_transport' => 'udp',
                'turn_username' => 'foo',
            ],
            $parser->getIceConnectionConfiguration()
        );
    }

    public function testTurnWithToken()
    {
        $parser = $this->getParser([
            ['urls' => 'turn:turn.example.com', 'username' => 'foo', 'password' => 'bar', 'credential_type' => 'token']
        ]);

        $this->assertParser(
            [],
            $parser->getIceConnectionConfiguration()
        );
    }

    public function testTurns()
    {
        $parser = $this->getParser([
            ['urls' => 'turns:turn.example.com']
        ]);

        $this->assertParser(
            [
                'turn_password' => null,
                'turn_server' => ['turn.example.com', 5349],
                'turn_ssl' => true,
                'turn_transport' => 'tcp',
                'turn_username' => null,
            ],
            $parser->getIceConnectionConfiguration()
        );
    }

    public function testTurnsOverUdp()
    {
        $parser = $this->getParser([
            ['urls' => 'turns:turn.example.com?transport=udp']
        ]);

        $this->assertParser(
            [],
            $parser->getIceConnectionConfiguration()
        );
    }

    public function testInvalidScheme()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid url: foo');
        $parser = $this->getParser([
            ['urls' => 'foo']
        ]);
        $parser->getIceConnectionConfiguration();
    }

    public function testInvalidUri()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid url: stun://');
        $parser = $this->getParser([
            ['urls' => 'stun:']
        ]);
        $parser->getIceConnectionConfiguration();
    }

    public function testStunParser()
    {
        $uri = $this->parseUri('stun:stun.services.mozilla.com');
        $this->assertEquals(
            ['host' => 'stun.services.mozilla.com', 'port' => 3478, 'scheme' => 'stun', 'transport' => null],
            (array)$uri
        );
    }

    public function testStunsParser()
    {
        $uri = $this->parseUri('stuns:stun.services.mozilla.com');
        $this->assertEquals(
            ['host' => 'stun.services.mozilla.com', 'port' => 5349, 'scheme' => 'stuns', 'transport' => null],
            (array)$uri
        );
    }

    public function testStunWithPort()
    {
        $uri = $this->parseUri('stun:stun.l.google.com:19302');
        $this->assertEquals(
            ['host' => 'stun.l.google.com', 'port' => 19302, 'scheme' => 'stun', 'transport' => null],
            (array)$uri
        );
    }

    public function testTurnParser()
    {
        $uri = $this->parseUri('turn:1.2.3.4');
        $this->assertEquals(
            ['host' => '1.2.3.4', 'port' => 3478, 'scheme' => 'turn', 'transport' => 'udp'],
            (array)$uri
        );
    }

    public function testTurnWithPortAndTransport()
    {
        $uri = $this->parseUri('turn:1.2.3.4:3478?transport=tcp');
        $this->assertEquals(
            ['host' => '1.2.3.4', 'port' => 3478, 'scheme' => 'turn', 'transport' => 'tcp'],
            (array)$uri
        );
    }

    public function testTurnsParser()
    {
        $uri = $this->parseUri('turns:1.2.3.4');
        $this->assertEquals(
            ['host' => '1.2.3.4', 'port' => 5349, 'scheme' => 'turns', 'transport' => 'tcp'],
            (array)$uri
        );
    }

    public function testTurnsWithPortAndTransport()
    {
        $uri = $this->parseUri('turns:1.2.3.4:1234?transport=tcp');
        $this->assertEquals(
            ['host' => '1.2.3.4', 'port' => 1234, 'scheme' => 'turns', 'transport' => 'tcp'],
            (array)$uri
        );
    }

    private function assertParser(array $expected, RTCIceProtocolConfigurationInterface $configuration)
    {
        $this->assertEquals($expected['stun_server'] ?? null, $configuration->getStunServer());
        $this->assertEquals($expected['turn_server'] ?? null, $configuration->getTurnServer());
        $this->assertEquals($expected['turn_username'] ?? null, $configuration->getTurnUsername());
        $this->assertEquals($expected['turn_password'] ?? null, $configuration->getTurnPassword());
        $this->assertEquals($expected['turn_transport'] ?? 'udp', $configuration->getTurnTransport());
        $this->assertEquals($expected['turn_ssl'] ?? null, $configuration->getTurnSSL());
    }

    private function getParser(array $configs): IceProtocolParser
    {
        $rtcIceServers = [];
        foreach ($configs as $config) {
            $rtcIceServer = new RTCIceServer();

            if ($config['urls']) {
                $rtcIceServer->setUrls($config['urls']);
            }

            if ($config['username']) {
                $rtcIceServer->setUsername($config['username']);
            }

            if ($config['password']) {
                $rtcIceServer->setCredential($config['password']);
            }

            if ($config['credential_type']) {
                $rtcIceServer->setCredentialType($config['credential_type']);
            }

            $rtcIceServers [] = $rtcIceServer;
        }

        return new IceProtocolParser($rtcIceServers);
    }

    private function parseUri(string $uri)
    {
        $protocol = new IceProtocolParser([]);
        return $protocol->parseUrl($uri);
    }
}
