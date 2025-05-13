<?php

namespace Tests\Webrtc\ICE;

use React\Datagram\Factory;
use React\Datagram\SocketInterface;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Protocol\Parser;
use function React\Async\await;

class MdnsServerMock
{
    private ?SocketInterface $server = null;

    public function __construct(private array $records)
    {
    }

    public function start(): void
    {
        $factory = new Factory();
        $this->server = await($factory->createServer('udp://0.0.0.0:5353'));
        $parser = new Parser();
        $dumper = new BinaryDumper();

        $this->server->on('message', function ($message, $address, $server) use ($parser, $dumper) {
            $parsedMessage = $parser->parseMessage($message);
            $domain = $parsedMessage->questions[0]->name;

            $response = new Message();
            $response->id = $parsedMessage->id;
            $response->qr = true;
            $response->aa = true;

            if(isset($this->records[$domain])) {
                $response->answers[] = new Record($domain, Message::TYPE_A, Message::CLASS_IN, 3600, $this->records[$domain]);
            }else{
                $response->rcode = Message::RCODE_NAME_ERROR;
            }

            $server->send($dumper->toBinary($response), $address);
        });
    }

    public function stop(): void
    {
        $this->server?->close();
    }
}