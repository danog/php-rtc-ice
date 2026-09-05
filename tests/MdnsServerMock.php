<?php

namespace Tests\Webrtc\ICE;

use Amp\Socket\UdpSocket;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\ResourceBuilderFactory;
use LibDNS\Records\ResourceQTypes;
use Throwable;
use Webrtc\MDNS\Factory;
use function Amp\async;
use function Amp\Socket\bindUdpSocket;

/**
 * A minimal mDNS responder, answering only the names it was given.
 */
class MdnsServerMock
{
    private ?UdpSocket $server = null;

    /**
     * @param array<string, string> $records Name to address.
     * @param string $address Where to listen. Defaults to the multicast group, but tests bind
     *                        a loopback port instead so they do not depend on group delivery.
     */
    public function __construct(private array $records, private readonly string $address = Factory::DNS)
    {
    }

    public function start(): string
    {
        // Windows rejects binding a socket directly to a multicast group address
        // (WSAEADDRNOTAVAIL). Group delivery does not reach loopback on CI anyway, so the
        // responder is only exercised where multicast genuinely works (the Good test guards
        // on Multicast::isAvailable()); elsewhere a wildcard bind on the same port keeps the
        // mock constructible so the resolution-failure paths can still run.
        try {
            $this->server = bindUdpSocket($this->address);
        } catch (\Amp\Socket\SocketException) {
            $port = substr($this->address, strrpos($this->address, ':') ?: 0);
            $this->server = bindUdpSocket('0.0.0.0' . $port);
        }

        $decoder = (new DecoderFactory())->create();
        $encoder = (new EncoderFactory())->create();
        $messages = new MessageFactory();
        $resources = (new ResourceBuilderFactory())->create();

        async(function () use ($decoder, $encoder, $messages, $resources): void {
            try {
                while (($received = $this->server?->receive()) !== null) {
                    [$address, $data] = $received;

                    try {
                        $request = $decoder->decode($data);
                    } catch (Throwable) {
                        continue;
                    }

                    $response = $messages->create(MessageTypes::RESPONSE);
                    $response->setID($request->getID());
                    $response->isAuthoritative(true);

                    foreach ($request->getQuestionRecords() as $question) {
                        // Questions arrive fully qualified, with the root label's trailing dot.
                        $name = rtrim((string) $question->getName(), '.');
                        if (!isset($this->records[$name])) {
                            continue;
                        }

                        $answer = $resources->build(ResourceQTypes::A);
                        $answer->setName($name);
                        $answer->getData()->getField(0)->setValue($this->records[$name]);
                        $answer->setTTL(3600);

                        $response->getAnswerRecords()->add($answer);
                    }

                    // A response with no answers says nothing under mDNS, where an absent name
                    // is met with silence rather than NXDOMAIN (RFC 6762 section 6).
                    if (count($response->getAnswerRecords()) === 0) {
                        continue;
                    }

                    $this->server?->send($address, $encoder->encode($response));
                }
            } catch (Throwable) {
                // The socket was closed while waiting, which is how stop() ends this loop.
            }
        });

        return (string) $this->server->getAddress();
    }

    public function stop(): void
    {
        $this->server?->close();
        $this->server = null;
    }
}
