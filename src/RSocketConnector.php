<?php

namespace RSocket;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use RSocket\core\RSocketRequester;
use React\Socket\Connector;
use React\Socket\ConnectionInterface;
use RSocket\transport\TcpDuplexConnection;
use RSocket\transport\PawlWebSocketDuplexConnection;
use Rx\React\Promise;
use function Ratchet\Client\connect;


class RSocketConnector
{
    public ?Payload $payload = null;
    public int $keepAliveInterval = 20;
    public int $keepAliveMaxLifeTime = 90;
    public string $dataMimeType = "application/json";
    public string $metadataMimeType = "message/x.rsocket.composite-metadata.v0";
    private ?SocketAcceptor $acceptor = null;
    private LoopInterface $loop;

    public static function create(LoopInterface $loop): RSocketConnector
    {
        $RSocketConnector = new self();
        $RSocketConnector->loop = $loop;
        return $RSocketConnector;
    }


    public function setupPayload(Payload $payload): RSocketConnector
    {
        $this->payload = $payload;
        return $this;
    }

    public function dataMimeType(string $dataMimeType): RSocketConnector
    {
        $this->dataMimeType = $dataMimeType;
        return $this;
    }

    public function metadataMimeType(string $metadataMimeType): RSocketConnector
    {
        $this->metadataMimeType = $metadataMimeType;
        return $this;
    }

    public function keepAlive(int $interval, int $maxLifeTime): RSocketConnector
    {
        $this->keepAliveInterval = $interval;
        $this->keepAliveMaxLifeTime = $maxLifeTime;
        return $this;
    }

    public function acceptor(SocketAcceptor $acceptor): RSocketConnector
    {
        $this->acceptor = $acceptor;
        return $this;
    }

    /**
     * @param string $url rsocket uri
     * @return PromiseInterface<RSocket>
     */
    public function connect(string $url): PromiseInterface
    {
        $setupPayload = new ConnectionSetupPayload();
        $setupPayload->setKeepAliveInterval($this->keepAliveInterval * 1000);
        $setupPayload->setKeepAliveMaxLifetime($this->keepAliveMaxLifeTime * 1000);
        $setupPayload->setMetadataMimeType($this->metadataMimeType);
        $setupPayload->setDataMimeType($this->dataMimeType);
        if ($this->payload !== null) {
            $setupPayload->metadata = $this->payload->metadata;
            $setupPayload->data = $this->payload->data;
        }
        $loop = $this->loop;
        $duplexConnPromise = null;
        $uriArray = parse_url($url);
        if ($uriArray !== false && array_key_exists("scheme", $uriArray)) {
            $scheme = $uriArray['scheme'];
            if ('tls' === $scheme || 'tcp' === $scheme) {
                $duplexConnPromise = (new Connector($loop))->connect($url)->then(function (ConnectionInterface $connection) {
                    return new TcpDuplexConnection($connection);
                });
            } else if ('ws' === $scheme) {
                $duplexConnPromise = connect($url)->then(function ($webSocket) {
                    return new PawlWebSocketDuplexConnection($webSocket);
                });
            }
            if ($duplexConnPromise !== null) {
                $acceptor = $this->acceptor;
                return $duplexConnPromise->then(function (DuplexConnection $duplexConn) use (&$setupPayload, &$acceptor, &$loop) {
                    $rsocketRequester = new RSocketRequester($loop, $duplexConn, $setupPayload, "requester");
                    if (!is_null($acceptor)) {
                        $responder = $acceptor->accept($setupPayload, $rsocketRequester);
                        if (is_null($responder)) {
                            $errorMessage = "RSOCKET-0x00000003: Connection refused, please check setup and security!";
                            $rsocketRequester->close();
                            return Promise::rejected($errorMessage);
                        }
                        $rsocketRequester->setResponder($responder);
                    }
                    $rsocketRequester->sendSetupPayload();
                    return $rsocketRequester;
                });
            }
        }
        return Promise::rejected($url . " unsupported");
    }

}
