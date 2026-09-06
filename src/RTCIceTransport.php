<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\ICE;

use Evenement\EventEmitter;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\ICE\Enum\IceRole;
use Webrtc\ICE\Enum\IceTransportState;
use Webrtc\ICE\Listener\IceConnectionClosedListener;
use Webrtc\ICE\Listener\IceConnectionDataListener;
use Webrtc\ICE\Listener\IceTransportDataListener;
use Webrtc\ICE\Listener\IceTransportDisconnectListener;
use Webrtc\Mixin\SerializableState;

/**
 * Class RTCIceTransport
 *
 * Handles ICE transport functionality including connection state,
 * sending data, and reacting to events emitted by the ICE connection.
 *
 * Listens to its {@see RTCIceConnection} through typed listener interfaces and re-delivers the
 * data and disconnect it sees to its own typed listeners (the DTLS transport and handshake). These
 * are ordinary objects, so the whole wiring is captured verbatim by a serialize cycle.
 */
final class RTCIceTransport extends EventEmitter implements RTCIceTransportInterface, IceConnectionDataListener, IceConnectionClosedListener
{
    /**
     * @var IceTransportState Current transport state.
     */
    private IceTransportState $state = IceTransportState::new;

    /**
     * @var RTCIceConnectionInterface ICE connection instance.
     */
    private RTCIceConnectionInterface $iceConnection;

    /**
     * @var bool Whether the ICE role has been set.
     */
    private bool $roleSet = false;

    /** @var \WeakMap<IceTransportDataListener, null> Listeners for application data from the connection. */
    private \WeakMap $dataListeners;

    /** @var \WeakMap<IceTransportDisconnectListener, null> Listeners for a disconnecting close/error. */
    private \WeakMap $disconnectListeners;

    /**
     * RTCIceTransport constructor.
     *
     * @param RTCIceGathererInterface $iceGatherer The ICE gatherer instance.
     * @param LoggerInterface|null $logger Optional PSR-3 logger.
     */
    public function __construct(
        private readonly RTCIceGathererInterface $iceGatherer,
        private readonly ?LoggerInterface $logger = null
    ) {
        /** @var \WeakMap<IceTransportDataListener, null> */
        $this->dataListeners = new \WeakMap();
        /** @var \WeakMap<IceTransportDisconnectListener, null> */
        $this->disconnectListeners = new \WeakMap();
        /** @var RTCIceConnection $iceConnection */
        $iceConnection = $iceGatherer->getIceConnection();
        $this->iceConnection = $iceConnection;
        $iceConnection->addDataListener($this);
        $iceConnection->addClosedListener($this);
    }

    /**
     * Register a listener for application data arriving over this transport.
     *
     * Typed replacement for on('data'); the listener is a plain object captured by serialization.
     */
    #[\Override]
    public function addDataListener(IceTransportDataListener $listener): void
    {
        $this->dataListeners[$listener] = null;
    }

    #[\Override]
    public function removeDataListener(IceTransportDataListener $listener): void
    {
        unset($this->dataListeners[$listener]);
    }

    /**
     * Register a listener notified when the transport reports a disconnecting close/error.
     */
    #[\Override]
    public function addDisconnectListener(IceTransportDisconnectListener $listener): void
    {
        $this->disconnectListeners[$listener] = null;
    }

    #[\Override]
    public function onIceConnectionData(string $data, int $componentId): void
    {
        foreach ($this->dataListeners as $listener => $_) {
            $listener->onIceTransportData($data, $componentId);
        }
    }

    #[\Override]
    public function onIceConnectionClosed(): void
    {
        $this->failure();
    }

    /**
     * Get the ICE gatherer instance.
     *
     * @return RTCIceGathererInterface
     */
    #[\Override]
    public function getIceGatherer(): RTCIceGathererInterface
    {
        return $this->iceGatherer;
    }

    /**
     * Get the ICE role.
     *
     * @return IceRole
     */
    #[\Override]
    public function getRole(): IceRole
    {
        return $this->iceConnection->getIceRole();
    }

    /**
     * Get the current ICE transport state.
     *
     * @return IceTransportState
     */
    public function getState(): IceTransportState
    {
        return $this->state;
    }

    /**
     * Add a remote ICE candidate.
     *
     * @param RTCIceCandidate $candidate
     * @return void
     */
    #[\Override]
    public function addRemoteCandidate(RTCIceCandidate $candidate): void
    {
        if (!$this->iceConnection->isRemoteCandidatesEnd()) {
            $this->iceConnection->addRemoteCandidate($candidate);
        }
    }

    /**
     * Get the list of all remote candidates.
     *
     * @return array
     */
    public function getRemoteCandidates(): array
    {
        return $this->iceConnection->getRemoteCandidates();
    }

    /**
     * Start the ICE transport with the given remote ICE parameters.
     *
     * @param RTCIceParameters $remoteIceParameters
     * @return void Returns once the transport has finished checking.
     * @throws InvalidArgumentException If the transport is already closed.
     */
    #[\Override]
    public function start(RTCIceParameters $remoteIceParameters): void
    {
        if ($this->state === IceTransportState::closed) {
            throw new InvalidArgumentException("RTCIceTransport is closed");
        }

        $this->setState(IceTransportState::checking);

        $this->iceConnection->setRemoteIsLite($remoteIceParameters->iceLite);
        $this->iceConnection->setRemoteUsername($remoteIceParameters->usernameFragment);
        $this->iceConnection->setRemotePassword($remoteIceParameters->password);

        try {
            $this->iceConnection->connect();
            $this->setState(IceTransportState::complete);
        } catch (Throwable) {
            $this->setState(IceTransportState::failed);
        }
    }

    /**
     * Gracefully shut down the ICE connection.
     *
     * @return void
     */
    #[\Override]
    public function stop(): void
    {
        if ($this->state === IceTransportState::closed) {
            return;
        }

        $this->setState(IceTransportState::closed);

        try {
            $this->iceConnection->close();
        } catch (Throwable) {
            // Silently catch errors to ensure graceful shutdown.
        }
    }

    /**
     * Forcefully stop the ICE connection without a graceful shutdown.
     *
     * @return void
     */
    public function stopIceConnection(): void
    {
        if ($this->state !== IceTransportState::closed) {
            $this->setState(IceTransportState::closed);
            $this->iceConnection->close();
        }
    }

    /**
     * Set the transport state and emit a statechange event.
     * Also removes listeners on shutdown to aid garbage collection.
     *
     * @param IceTransportState $state
     * @return void
     */
    private function setState(IceTransportState $state): void
    {
        if ($state !== $this->state) {
            $this->logger?->debug(sprintf(
                "Ice transport state has been changed from %s to %s",
                $this->state->name,
                $state->name
            ));

            $this->state = $state;
            $this->emit("statechange", [$state]);

            if ($state === IceTransportState::closed) {
                if ($this->iceGatherer instanceof EventEmitter) {
                    $this->iceGatherer->removeAllListeners();
                }
                $this->removeAllListeners();
                /** @var \WeakMap<IceTransportDataListener, null> */
                $this->dataListeners = new \WeakMap();
                /** @var \WeakMap<IceTransportDisconnectListener, null> */
                $this->disconnectListeners = new \WeakMap();
            }
        }
    }

    /**
     * Get the underlying ICE connection.
     *
     * @return RTCIceConnectionInterface
     */
    #[\Override]
    public function getIceConnection(): RTCIceConnectionInterface
    {
        return $this->iceConnection;
    }

    /**
     * Send data over the ICE connection.
     *
     * @param string $bytes
     * @return void
     */
    #[\Override]
    public function send(string $bytes): void
    {
        $this->iceConnection->sendData($bytes);
    }

    /**
     * Notify the connection that all remote candidates have been sent.
     *
     * @return void
     */
    public function endRemoteCandidate(): void
    {
        $this->iceConnection->endOfRemoteCandidate();
    }

    /**
     * Check if the ICE role has been set.
     *
     * @return bool
     */
    #[\Override]
    public function isRoleSet(): bool
    {
        return $this->roleSet;
    }

    /**
     * Mark the ICE role as a set.
     *
     * @param bool $roleSet
     * @return void
     */
    #[\Override]
    public function setRoleSet(bool $roleSet): void
    {
        $this->roleSet = $roleSet;
    }

    /**
     * Transition to the "failed" state if the connection was previously "complete".
     *
     * @return void
     */
    private function failure(): void
    {
        if ($this->state === IceTransportState::complete) {
            $this->setState(IceTransportState::failed);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $state = SerializableState::export($this, [
            // WeakMaps cannot be serialized; snapshot their keys and rebuild on the far side.
            'dataListeners' => ['__uninitialized' => true],
            'disconnectListeners' => ['__uninitialized' => true],
        ]);
        $state['__dataListeners'] = SerializableState::weakMapToList($this->dataListeners);
        $state['__disconnectListeners'] = SerializableState::weakMapToList($this->disconnectListeners);

        return $state;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        /** @var list<IceTransportDataListener> $dataListeners */
        $dataListeners = $data['__dataListeners'] ?? [];
        /** @var list<IceTransportDisconnectListener> $disconnectListeners */
        $disconnectListeners = $data['__disconnectListeners'] ?? [];
        unset($data['__dataListeners'], $data['__disconnectListeners']);

        SerializableState::import($this, $data);
        /** @var \WeakMap<IceTransportDataListener, null> */
        $this->dataListeners = SerializableState::listToWeakMap($dataListeners);
        /** @var \WeakMap<IceTransportDisconnectListener, null> */
        $this->disconnectListeners = SerializableState::listToWeakMap($disconnectListeners);
    }
}
