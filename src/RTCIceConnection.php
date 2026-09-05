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
use Ramsey\Uuid\Uuid;
use Random\RandomException;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Socket\InternetAddress;
use Revolt\EventLoop;
use Throwable;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;
use Webrtc\ICE\Enum\CandidateType;
use Webrtc\ICE\Enum\CheckListState;
use Webrtc\ICE\Enum\IceRole;
use Webrtc\ICE\Enum\RTCIceCandidatePairStats;
use Webrtc\ICE\Enum\TransportPolicyType;
use Webrtc\ICE\Enum\TransportType;
use Webrtc\ICE\Trait\DNS;
use Webrtc\ICE\Trait\Mdns;
use Webrtc\ICE\Trait\NetworkAdapter;
use Webrtc\STUN\Enum\MessageAttribute;
use Webrtc\STUN\Enum\MessageClass;
use Webrtc\STUN\Enum\MessageMethod;
use Webrtc\STUN\Exception\TransactionException;
use Webrtc\STUN\Exception\TransactionExceptionInterface;
use Webrtc\STUN\IceConnectionProtocolInterface;
use Webrtc\STUN\Message\Message;
use Webrtc\STUN\Message\MessageInterface;
use Webrtc\STUN\ReceiverInterface;
use Webrtc\STUN\Stun;
use Webrtc\STUN\StunInterface;
use Webrtc\TURN\Turn;
use Webrtc\TURN\TurnInterface;
use function Amp\async;

/**
 * RTCIceConnection - ICE Connection Implementation
 *
 * Implements the Interactive Connectivity Establishment (ICE) protocol for WebRTC.
 * Handles candidate gathering, connectivity checks, and network address translation.
 *
 * Key Features:
 * - Implements full ICE protocol (RFC 8445)
 * - Supports both controlling and controlled roles
 * - Handles STUN and TURN server interactions
 * - Manages candidate pairs and connectivity checks
 * - Implements consent freshness checks (RFC 7675)
 * - Supports IPv4 and IPv6 candidates
 * - Provides event-driven interface for connection state changes
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8445 ICE RFC 8445
 * @see https://www.rfc-editor.org/rfc/rfc7675 Consent Freshness RFC 7675
 * @api
 */
class RTCIceConnection extends EventEmitter implements RTCIceConnectionInterface, ReceiverInterface
{
    use NetworkAdapter, Mdns, DNS;

    /* Constants */

    /** @var int Maximum number of binding retry attempts */
    private const RETRY_BINDING_MAX = 10;

    /**
     * Retransmissions for one connectivity-check transaction (RFC 5389 RTO
     * doubling). A single 500ms try is not enough on a loaded macOS runner:
     * the peer's reply arrives, but after the transaction has already been
     * declared timed out, and with one pair ICE fails.
     */
    private const CHECK_RETRANSMISSIONS = 3;

    /** @var int Maximum number of consent failures before closing */
    private const CONSENT_FAILURES = 6;

    /** @var int Interval between consent checks in seconds */
    private const CONSENT_INTERVAL = 5;

    /* Properties */

    /** @var int tiebreaker value for role conflicts */
    private int $tieBreaker;

    /** @var string Local ICE username fragment */
    private string $localUsername;

    /** @var string Local ICE password */
    private string $localPassword;

    /** @var string|null Remote ICE username fragment */
    private ?string $remoteUsername = null;

    /** @var string|null Remote ICE password */
    private ?string $remotePassword = null;

    /** @var array<int> Component IDs (typically 1 for RTP, 2 for RTCP) */
    private array $componentIds;

    /** @var RTCIceCandidatePair[] List of candidate pairs to check */
    private array $checkList = [];

    /** @var array<array-key, array{0: MessageInterface, 1: InternetAddress, 2: IceConnectionProtocolInterface}> Early checks received before checklist creation */
    private array $earlyChecks = [];

    /** @var RTCIceCandidate[] Local ICE candidates */
    private array $localCandidates = [];

    /** @var RTCIceCandidatePair[] Nominated candidate pairs */
    private array $nominated = [];

    /** @var array<int> Components being nominated */
    private array $nominating = [];

    /** @var array<array-key, StunInterface|TurnInterface> Protocol instances */
    private array $protocols = [];

    /** @var RTCIceCandidate[] Remote ICE candidates */
    private array $remoteCandidates = [];

    /** @var bool Whether to use IPv4 candidates */
    private bool $useIPv4 = true;

    /** @var bool Whether to use IPv6 candidates */
    private bool $useIPv6 = true;

    /** @var bool Whether the connection is closed */
    private bool $closed = false;

    /** @var bool Whether remote agent is lite implementation */
    private bool $remoteIsLite = false;

    /** @var bool Whether checklist processing is complete */
    private bool $checkListDone = false;

    /** @var bool Whether early checks are processed */
    private bool $earlyChecksDone = false;

    /** @var bool Whether remote candidates are complete */
    private bool $remoteCandidatesEnd = false;

    /** @var bool Whether local candidates are complete */
    private bool $localCandidatesEnd = false;

    /** @var bool Whether local candidate gathering has started */
    private bool $localCandidatesStart = false;

    /** @var CheckListState|null Current state of the checklist */
    private ?CheckListState $checkListState = null;

    /** @var TransportPolicyType Transport policy (all, relay, etc.) */
    private TransportPolicyType $transportPolicy = TransportPolicyType::ALL;

    /** @var LoggerInterface|null Logger instance */
    private ?LoggerInterface $logger = null;

    /** @var DeferredFuture<void>|null Current binding-check completion, while ICE negotiation is active. */
    private ?DeferredFuture $bindingCheck = null;

    /** Whether checklist progression has already been queued. */
    private bool $bindingCheckScheduled = false;

    /** @var string|null Handle of the consent check timer */
    private ?string $queryConsentTimer = null;

    /** @var bool Whether waiting for binding response */
    private bool $isBindingWait = false;

    /** @var int Count of failed binding attempts */
    private int $tryFailedCount = 0;

    /** @var ?array Range of ports that host candidates allow */
    private ?array $icePortRange = null;

    /**
     * @var array<int, string>|null If set, this will result in all host candidates (which normally have a private IP address)
     * to be rewritten with the public address provided in the settings
     */
    private ?array $nat1to1 = null;

    /**
     * Constructor - Creates a new ICE connection
     *
     * @param RTCIceProtocolConfigurationInterface $configuration ICE protocol configuration
     * @param IceRole $iceRole The role of this ICE agent (controlling/controlled)
     * @throws RandomException If random number generation fails
     */
    public function __construct(private readonly RTCIceProtocolConfigurationInterface $configuration, private IceRole $iceRole = IceRole::Controlled)
    {
        $this->localUsername = Utils::getRandomString(4);
        $this->localPassword = Utils::getRandomString(22);
        $this->tieBreaker = Utils::generateRandom64BitInt();
        $this->componentIds = range(1, 1);
    }

    /**
     * @return bool
     */
    #[Override]
    public function isUseIPv4(): bool
    {
        return $this->useIPv4;
    }

    /**
     * @param bool $useIPv4
     * @return void
     */
    #[Override]
    public function setUseIPv4(bool $useIPv4): void
    {
        $this->useIPv4 = $useIPv4;
    }

    /**
     * @return bool
     */
    #[Override]
    public function isUseIPv6(): bool
    {
        return $this->useIPv6;
    }

    /**
     * @param bool $useIPv6
     * @return void
     */
    #[Override]
    public function setUseIPv6(bool $useIPv6): void
    {
        $this->useIPv6 = $useIPv6;
    }

    /**
     * @return string
     */
    #[Override]
    public function getLocalUsername(): string
    {
        return $this->localUsername;
    }

    /**
     * @param string $localUsername
     * @return void
     */
    #[Override]
    public function setLocalUsername(string $localUsername): void
    {
        $this->localUsername = $localUsername;
    }

    /**
     * @return string
     */
    #[Override]
    public function getLocalPassword(): string
    {
        return $this->localPassword;
    }

    /**
     * @param string $localPassword
     * @return void
     */
    #[Override]
    public function setLocalPassword(string $localPassword): void
    {
        $this->localPassword = $localPassword;
    }

    /**
     * @return RTCIceCandidate[]
     */
    #[Override]
    public function getLocalCandidates(): array
    {
        return $this->localCandidates;
    }

    /**
     * @param RTCIceCandidate[] $localCandidates
     * @return void
     */
    #[Override]
    public function setLocalCandidates(array $localCandidates): void
    {
        $this->localCandidates = $localCandidates;
    }

    /**
     * @return RTCIceCandidate[]
     */
    #[Override]
    public function getRemoteCandidates(): array
    {
        return $this->remoteCandidates;
    }

    /**
     * @param RTCIceCandidate[] $remoteCandidates
     * @return void
     */
    #[Override]
    public function setRemoteCandidates(array $remoteCandidates): void
    {
        $this->remoteCandidates = $remoteCandidates;
    }

    /**
     * @return string|null
     */
    #[Override]
    public function getRemoteUsername(): ?string
    {
        return $this->remoteUsername;
    }

    /**
     * @param string|null $remoteUsername
     * @return void
     */
    #[Override]
    public function setRemoteUsername(?string $remoteUsername): void
    {
        $this->remoteUsername = $remoteUsername;
    }

    /**
     * @return string|null
     */
    #[Override]
    public function getRemotePassword(): ?string
    {
        return $this->remotePassword;
    }

    /**
     * @param string|null $remotePassword
     * @return void
     */
    #[Override]
    public function setRemotePassword(?string $remotePassword): void
    {
        $this->remotePassword = $remotePassword;
    }

    /**
     * @return array<int>
     */
    #[Override]
    public function getComponentIds(): array
    {
        return $this->componentIds;
    }

    /**
     * @param array<int> $componentIds
     * @return void
     */
    #[Override]
    public function setComponentIds(array $componentIds): void
    {
        $this->componentIds = $componentIds;
    }

    /**
     * @return bool
     */
    #[Override]
    public function isRemoteIsLite(): bool
    {
        return $this->remoteIsLite;
    }

    /**
     * @param bool $remoteIsLite
     * @return void
     */
    #[Override]
    public function setRemoteIsLite(bool $remoteIsLite): void
    {
        $this->remoteIsLite = $remoteIsLite;
    }

    /**
     * @param LoggerInterface $logger
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Gather local ICE candidates
     *
     * Collects host candidates and queries STUN/TURN servers as configured.
     * Candidates are gathered for each component ID (typically 1 for RTP, 2 for RTCP).
     *
     * @throws RandomException If random number generation fails
     * @throws Throwable If candidate gathering fails
     * @see https://datatracker.ietf.org/doc/html/rfc8445#section-2.1
     */
    #[Override]
    public function gatherCandidates(): void
    {
        if (!$this->localCandidatesStart) {
            $this->localCandidatesStart = true;
            foreach ($this->componentIds as $componentId) {
                $this->localCandidates = array_merge($this->getCandidate($componentId), $this->localCandidates);
            }

            $this->localCandidatesEnd = true;
        }
    }

    /**
     * Gathers all types of ICE candidates for a specific component.
     *
     * This method combines host candidates, server-reflexive candidates (from STUN servers),
     * and relayed candidates (from TURN servers) based on the current configuration.
     *
     * @param int $componentId The component ID to gather candidates for
     * @return RTCIceCandidate[] An array of RTCIceCandidate objects
     * @throws RandomException If there's an error in random generation
     * @throws Throwable For any other exceptions that might occur during candidate gathering
     */
    private function getCandidate(int $componentId): array
    {

        // Gather host candidates
        $candidates = $this->getHostCandidates($componentId);

        // Query the STUN server for IPv4 server-reflexive candidates and get STUN candidates.
        if ($this->configuration->getStunServer() !== null) {
            $candidates = array_merge($candidates, $this->getServerReflexiveCandidates($componentId));
        }

        // Connect to TURN server, get relayed candidate and return candidates
        if ($this->configuration->getTurnServer() !== null) {
            if ($turn = $this->getRelayedCandidate()) {
                $candidate = $candidates [] = $this->getTurnCandidate($turn, $componentId);
                $turn->setCandidate($candidate);
                $this->protocols[$turn->getId()] = $turn;
            }
        }

        return $candidates;
    }

    /**
     * Discovers and creates host ICE candidates.
     *
     * This method gets local IP addresses and creates host candidates based on
     * the configured transport policy. Only creates candidates when the transport
     * policy is set to 'all'.
     *
     * @param int $componentId The component ID to create candidates for
     * @return RTCIceCandidate[] An array of host RTCIceCandidate objects
     * @throws Throwable For any exceptions that might occur during candidate creation
     */
    private function getHostCandidates(int $componentId): array
    {
        $addresses = $this->nat1to1 ?? $this->getHostAddresses($this->useIPv4, $this->useIPv6);
        $candidates = [];

        foreach ($addresses as $address) {
            if ($protocol = $this->getStun($address)) {
                if ($this->transportPolicy === TransportPolicyType::ALL) {
                    $candidate = $candidates [] = $this->createCandidate($protocol->getId(), $protocol->getLocalHost(), $protocol->getLocalPort(), $componentId, CandidateType::host);
                    $protocol->setCandidate($candidate);
                }

                $this->protocols[$protocol->getId()] = $protocol;
            }
        }

        return $candidates;
    }

    /**
     * Discovers and creates server-reflexive candidates using STUN servers.
     *
     * This method iterates through available protocols to get server-reflexive
     * candidates for IPv4 addresses by contacting the configured STUN server.
     *
     * @param int $componentId The component ID to create candidates for
     * @return RTCIceCandidate[] An array of server-reflexive RTCIceCandidate objects
     * @throws RandomException If there's an error in random generation
     * @throws Throwable For any other exceptions during candidate discovery
     */
    private function getServerReflexiveCandidates(int $componentId): array
    {
        $candidates = [];

        foreach ($this->protocols as $protocol) {
            if ($protocol instanceof Stun && Utils::IPVersion($protocol->getLocalHost()) === 4) {
                if ($candidate = $this->getServerReflexiveCandidate($protocol, $componentId)) {
                    $candidates [] = $candidate;
                    $protocol->setCandidate($candidate);
                    $this->protocols [$protocol->getId()] = $protocol;
                }
            }
        }

        return $candidates;
    }

    /**
     * Attempts to get a single server-reflexive candidate via a STUN server.
     *
     * Sends a binding request to the configured STUN server and creates a candidate
     * from the XOR_MAPPED_ADDRESS in the response.
     *
     * @param StunInterface $stun The STUN interface to use for the request
     * @param int $componentId The component ID to create the candidate for
     * @return RTCIceCandidate|false A server-reflexive candidate if successful, false otherwise
     * @throws RandomException If there's an error in random generation
     * @throws Throwable For any other exceptions during the STUN request
     */
    private function getServerReflexiveCandidate(StunInterface $stun, int $componentId): RTCIceCandidate|false
    {
        $stunServer = $this->configuration->getStunServer();
        if ($stunServer === null || $stunServer === []) {
            return false;
        }
        /** @var array{0: string, 1: int<0, 65535>} $stunServer */
        $message = Message::new(MessageClass::REQUEST, MessageMethod::BINDING);
        try {
            $stunServerIp = $this->resolveDNS($stunServer);
            [$response,] = $stun->request($message, new InternetAddress($stunServerIp[0], $stunServerIp[1]), null);
            /** @var InternetAddress $mappedAddress */
            $mappedAddress = $response->attributes()->get(MessageAttribute::XOR_MAPPED_ADDRESS);

            return $this->createCandidate($stun->getId(), $mappedAddress->getAddress(), $mappedAddress->getPort(), $componentId, CandidateType::srflx, $stun->getLocalHost(), $stun->getLocalPort());
        } catch (Throwable $e) {
            $this->logger?->error(sprintf("Could not request stun server: %s - %s", $e->getMessage(), implode(":", $stunServer)));
            // Leave the socket open: it still owns the host candidate. Closing it
            // here would drop host connectivity whenever srflx gathering fails
            // (unreachable STUN server, timeout, DNS), which is the default
            // RTCPeerConnection configuration.
            return false;
        }
    }

    /**
     * Attempts to connect to a TURN server and get a relayed candidate.
     *
     * Creates and connects to a TURN server using the configured credentials
     * and settings.
     *
     * @return Turn|false A TURN interface if successful, false otherwise
     * @throws Throwable For any other exceptions during TURN server connection
     */
    private function getRelayedCandidate(): Turn|false
    {
        $turnServer = $this->configuration->getTurnServer();
        if ($turnServer === null) {
            return false;
        }
        /** @var array{0: string, 1: int<0, 65535>} $turnServer */

        try {
            $configuration = clone $this->configuration;
            $configuration->setTurnServer($this->resolveDNS($turnServer));

            $turn = Turn::create($configuration, $this, $this->logger);
            $turn->connect();
            return $turn;
        } catch (Throwable $e) {
            $this->logger?->error("Couldn't connect to Turn server {$e->getMessage()}", ["TurnServer" => $turnServer]);
            return false;
        }
    }

    /**
     * Creates a relay candidate from a TURN connection.
     *
     * Uses the relayed address and port obtained from the TURN server to create
     * a relay candidate.
     *
     * @param TurnInterface $turn The TURN interface containing the relayed information
     * @param int $componentId The component ID to create the candidate for
     * @return RTCIceCandidate A relay ICE candidate
     */
    private function getTurnCandidate(TurnInterface $turn, int $componentId): RTCIceCandidate
    {
        return $this->createCandidate(
            $turn->getId(),
            $turn->getRelayedHost(),
            $turn->getRelayedPort(),
            $componentId,
            CandidateType::relay,
            $turn->getLocalHost(),
            $turn->getLocalPort(),
            $this->configuration->getTurnTransport() === "tcp" ? TransportType::tcp : TransportType::udp
        );
    }

    /**
     * Creates a STUN interface for a given local address.
     *
     * Attempts to create a STUN interface that will be used for candidate gathering.
     *
     * @param string $address The local address to bind the STUN interface to
     * @return StunInterface|false A STUN interface if successful, false otherwise
     */
    private function getStun(string $address): StunInterface|false
    {
        try {
            return Stun::create($this, new InternetAddress($address, 0), $this->logger, $this->icePortRange);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Creates an RTCIceCandidate object with the provided parameters.
     *
     * Factory method for creating standardized ICE candidate objects with the necessary
     * attributes based on the type of candidate being created.
     *
     * @param string|null $protocolId The protocol ID associated with this candidate
     * @param string $host The candidate's IP address
     * @param int $port The candidate's port
     * @param int $componentId The component ID for this candidate
     * @param CandidateType $type The type of candidate (host, srflx, relay)
     * @param string|null $relatedAddress The related address for non-host candidates
     * @param int|null $relatedPort The related port for non-host candidates
     * @param TransportType $transportType The transport protocol (UDP or TCP)
     * @return RTCIceCandidate A configured ICE candidate object
     */
    private function createCandidate(
        ?string       $protocolId,
        string        $host,
        int           $port,
        int           $componentId,
        CandidateType $type,
        ?string       $relatedAddress = null,
        ?int          $relatedPort = null,
        TransportType $transportType = TransportType::udp
    ): RTCIceCandidate
    {
        return RTCIceCandidate::create(
            $protocolId,
            $componentId,
            $host,
            $port,
            $type,
            $transportType,
            $relatedAddress,
            $relatedPort
        );
    }

    /**
     * Adds a remote ICE candidate to the connection.
     *
     * This method processes and validates a remote candidate before adding it to the connection's
     * remote candidate list. It handles mDNS resolution for applicable candidates and creates
     * appropriate check lists for connectivity testing.
     *
     * @param RTCIceCandidate $remoteCandidate The remote candidate to add
     * @return void
     * @throws InvalidArgumentException If remote candidates are added after end-of-candidates has been signaled
     */
    #[Override]
    public function addRemoteCandidate(RTCIceCandidate $remoteCandidate): void
    {
        if ($this->remoteCandidatesEnd) {
            throw new InvalidArgumentException("Unable to add remote candidate after the end-of-candidates stage.");
        }

        // Resolve mDNS candidate
        if ($this->isMdnsDomain($remoteCandidate->getHost())) {
            $ip = $this->resolveMdns($remoteCandidate->getHost());
            if ($ip !== false) {
                $remoteCandidate->setHost($ip);
            } else {
                $this->logger?->error("Couldn't resolve the remote host", ["RemoteHost" => $remoteCandidate->getHost()]);
                return;
            }
        }

        // Validate the remote candidate add it
        if ($this->validateRemoteCandidate($remoteCandidate)) {
            $this->remoteCandidates [] = $remoteCandidate;
            $this->createCheckList($remoteCandidate);
            $this->scheduleBindingCheck();
        }
    }

    /**
     * Validates that a remote candidate meets the requirements for use.
     *
     * This method verifies that the remote candidate has a supported type and
     * that its host address is a valid IP address.
     *
     * @param RTCIceCandidate $remoteCandidate The remote candidate to validate
     * @return bool True if the candidate is valid, false otherwise
     */
    private function validateRemoteCandidate(RTCIceCandidate $remoteCandidate): bool
    {
        if (!in_array($remoteCandidate->getType()->name, ["host", "relay", "srflx"])) {
            $this->logger?->error(sprintf("Invalid candidate type %s", $remoteCandidate->getType()->name));
            return false;
        }

        $ip = trim($remoteCandidate->getHost(), '[]');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->logger?->error(sprintf("Invalid candidate host %s", $remoteCandidate->getHost()));
            return false;
        }

        return true;
    }

    /**
     * Signals that all remote candidates have been received.
     *
     * This method marks the end of the remote candidate gathering phase and triggers
     * the reset of component IDs to ensure only components with paired candidates are used.
     *
     * @return void
     */
    #[Override]
    public function endOfRemoteCandidate(): void
    {
        $this->resetComponentIds();
        $this->remoteCandidatesEnd = true;
        $this->scheduleBindingCheck();
    }

    /**
     * Removes component IDs that have no matching remote candidates.
     *
     * This method is called after all remote candidates have been received to
     * identify and remove any component IDs for which the remote party has not
     * provided any candidates.
     *
     * @return void
     */
    private function resetComponentIds(): void
    {
        // Collect seen component IDs
        $seenComponentIds = array_unique(array_map(function (RTCIceCandidate $candidate) {
            return $candidate->getComponentId();
        }, $this->remoteCandidates));
        $missingComponentIds = array_diff($this->componentIds, $seenComponentIds);

        if (!empty($missingComponentIds)) {
            $this->logger?->debug("Component IDs " . implode(", ", $missingComponentIds) . " have no candidate pairs");
            $this->componentIds = $seenComponentIds;
        }
    }

    /**
     * Establishes an ICE connection by setting up checklists and starting the connectivity checks.
     *
     * This method validates connection preconditions, creates the checklist of candidate pairs,
     * unfreezes initial candidates, and processes any early checks that arrived before
     * the checklist was created. It then performs binding checks to establish connectivity
     * and begins periodic consent freshness tests once a connection is established.
     *
     * @return void Returns once the ICE connection is established.
     * @throws RuntimeException If ICE negotiation fails or preconditions aren’t met
     */
    #[Override]
    public function connect(): void
    {
        $this->validateConnectionPreconditions();
        $this->createCheckList();
        $this->unfreezeChecks();
        $this->processEarlyChecks();

        try {
            $this->bindCheck()->await();
        } catch (Throwable $e) {
            $this->close();
            throw new RuntimeException("ICE negotiation failed: " . $e->getMessage(), (int)$e->getCode(), $e);
        }

        if ($this->checkListState === CheckListState::ICE_FAILED) {
            $this->close();
            throw new RuntimeException("ICE negotiation failed");
        }

        // Start consent freshness tests
        $this->periodicConsentCheck();
    }

    /**
     * Validates that all required preconditions for a connection establishment are met.
     *
     * Verifies that local candidates have been gathered and that remote credentials
     * (username and password) have been set before attempting to establish a connection.
     *
     * @throws RuntimeException If local candidates gathering wasn’t performed or remote credentials are missing
     * @throws RandomException If there's an error in random generation
     * @throws Throwable For other exceptions that might occur during validation
     */
    private function validateConnectionPreconditions(): void
    {
        if (!$this->localCandidatesEnd) {
            $this->close();
            throw new RuntimeException("Local candidates gathering was not performed");
        }

        if ($this->remoteUsername === null || $this->remotePassword === null) {
            $this->close();
            throw new RuntimeException("Remote username or password is missing");
        }
    }

    /**
     * Processes connectivity checks that arrived before checklist creation.
     *
     * Handles any STUN binding requests received before the checklist
     * was created by passing them to the checkIncoming method.
     *
     * @return void
     */
    private function processEarlyChecks(): void
    {
        foreach ($this->earlyChecks as $earlyCheck) {
            $this->checkIncoming($earlyCheck[0], $earlyCheck[1], $earlyCheck[2]);
        }

        $this->earlyChecks = [];
        $this->earlyChecksDone = true;
    }

    /**
     * Creates a checklist of candidate pairs for connectivity testing.
     *
     * Pairs each compatible local protocol with remote candidates to create
     * candidate pairs that will be tested for connectivity. Follows the process
     * described in RFC 8445 section 6.1.2.2.
     *
     * @param RTCIceCandidate|null $rCandidate Optional specific remote candidate to pair with locals
     * @return void
     * @see https://datatracker.ietf.org/doc/html/rfc8445#section-6.1.2.2
     */
    private function createCheckList(?RTCIceCandidate $rCandidate = null): void
    {
        $remoteCandidates = $rCandidate ? [$rCandidate] : $this->remoteCandidates;
        foreach ($remoteCandidates as $remoteCandidate) {
            foreach ($this->protocols as $protocol) {
                $localCandidate = $protocol->getCandidate();
                if ($localCandidate === null) {
                    continue;
                }
                assert($localCandidate instanceof RTCIceCandidate);
                if ($localCandidate->isPairableWith($remoteCandidate) && !$this->findPair($protocol, $remoteCandidate)) {
                    $this->checkList[] = new RTCIceCandidatePair($protocol, $remoteCandidate);
                }
            }
        }

        $this->sortCheckList();
    }

    /**
     * Sorts the checklist in decreasing order of candidate pair priority.
     *
     * Implements the algorithm defined in RFC 8445 section 6.1.2.3 to calculate
     * pair priorities and sort the checklist accordingly.
     *
     * @return void
     * @see https://datatracker.ietf.org/doc/html/rfc8445#section-6.1.2.3
     */
    private function sortCheckList(): void
    {
        $pairPriority = function (RTCIceCandidatePair $pair): int {
            $G = $this->isControllingRole() ? $pair->getLocalCandidate()->getPriority() : $pair->getRemoteCandidate()->getPriority();
            $D = $this->isControllingRole() ? $pair->getRemoteCandidate()->getPriority() : $pair->getLocalCandidate()->getPriority();

            return -((1 << 32) * min($G, $D) + 2 * max($G, $D) + ($G > $D ? 1 : 0));
        };

        // Sort the candidate pairs using the priority function
        usort($this->checkList, function (RTCIceCandidatePair $a, RTCIceCandidatePair $b) use ($pairPriority): int {
            return $pairPriority($a) <=> $pairPriority($b);
        });
    }

    /**
     * Finds an existing candidate pair in the checklist.
     *
     * Searches for a candidate pair that matches the given protocol and remote candidate.
     *
     * @param IceConnectionProtocolInterface $protocol The local protocol interface
     * @param RTCIceCandidate $remoteCandidate The remote candidate
     * @return RTCIceCandidatePair|null The matching candidate pair or null if none found
     */
    private function findPair(IceConnectionProtocolInterface $protocol, RTCIceCandidate $remoteCandidate): ?RTCIceCandidatePair
    {
        foreach ($this->checkList as $candidatePair) {
            if ($candidatePair->getProtocol()->getId() === $protocol->getId() && $candidatePair->getRemoteCandidate() === $remoteCandidate) {
                return $candidatePair;
            }
        }
        return null;
    }

    /**
     * Unfreezes candidate pairs to begin connectivity checks.
     *
     * Initiates the connectivity check process by changing the state of the
     * first pair for the first component from "frozen" to "waiting", then
     * unfreezes additional pairs with different foundations.
     *
     * @return void
     */
    #[Override]
    public function unfreezeChecks(): void
    {
        $firstPair = $this->getFirstPairForFirstComponent();

        if ($firstPair && $firstPair->getState() === RTCIceCandidatePairStats::FROZEN) {
            $this->changeCandidatePairState($firstPair, RTCIceCandidatePairStats::WAITING);
        }

        $this->unfreezePairsWithDifferentFoundations($firstPair);
    }

    /**
     * Gets the first candidate pair for the first component in the checklist.
     *
     * Identifies the pair with the lowest component ID, which is typically
     * the first to be checked according to the ICE algorithm.
     *
     * @return RTCIceCandidatePair|null The first candidate pair or null if none exists
     */
    private function getFirstPairForFirstComponent(): ?RTCIceCandidatePair
    {
        if ($this->componentIds === []) {
            return null;
        }

        $minComponentId = min($this->componentIds);
        foreach ($this->checkList as $pair) {
            if ($pair->getComponentId() === $minComponentId) {
                return $pair;
            }
        }
        return null;
    }

    /**
     * Unfreezes pairs that have the same component ID but different foundations.
     *
     * Part of the ICE algorithm to ensure diverse connectivity checks across
     * different network paths.
     *
     * @param RTCIceCandidatePair|null $firstPair The reference pair to compare foundations against
     * @return void
     */
    private function unfreezePairsWithDifferentFoundations(?RTCIceCandidatePair $firstPair): void
    {
        if (!$firstPair) {
            return;
        }

        $seenFoundations = [$firstPair->getLocalCandidate()->getFoundation()];
        foreach ($this->checkList as $pair) {
            if (
                $pair->getComponentId() === $firstPair->getComponentId() &&
                !in_array($pair->getLocalCandidate()->getFoundation(), $seenFoundations, true) &&
                $pair->getState() === RTCIceCandidatePairStats::FROZEN
            ) {
                $this->changeCandidatePairState($pair, RTCIceCandidatePairStats::WAITING);
                $seenFoundations[] = $pair->getLocalCandidate()->getFoundation();
            }
        }
    }

    /**
     * Changes the state of a candidate pair and logs the transition.
     *
     * Updates the state of a candidate pair as it progresses through the
     * connectivity check process.
     *
     * @param RTCIceCandidatePair $pair The candidate pair to update
     * @param RTCIceCandidatePairStats $state The new state to set
     * @return void
     */
    public function changeCandidatePairState(RTCIceCandidatePair $pair, RTCIceCandidatePairStats $state): void
    {
        $this->logger?->debug(sprintf(
            "Candidate pair state changed (%s): %s -> %s",
            $pair,
            $pair->getState()->name,
            $state->name
        ));
        $pair->setState($state);
    }

    /**
     * Handles incoming STUN binding requests for connectivity checks.
     *
     * Processes incoming connectivity checks by finding or creating the appropriate
     * remote candidate, matching it with a local protocol to form a candidate pair,
     * and potentially starting a binding check on that pair.
     *
     * @param MessageInterface $message The incoming STUN message
     * @param InternetAddress $address The source address of the message
     * @param IceConnectionProtocolInterface $protocol The local protocol that received the message
     * @return void
     * @throws RandomException If there's an error in random generation
     */
    #[Override]
    public function checkIncoming(MessageInterface $message, InternetAddress $address, IceConnectionProtocolInterface $protocol): void
    {
        $localCandidate = $protocol->getCandidate();
        assert($localCandidate !== null);

        $remoteCandidate = $this->findOrCreateRemoteCandidate($message, $address, $localCandidate->getComponentId());
        $pair = $this->findPair($protocol, $remoteCandidate) ?? $this->createNewCandidatePair($protocol, $remoteCandidate);
        if (in_array($pair->getState(), [RTCIceCandidatePairStats::WAITING, RTCIceCandidatePairStats::FAILED], true)) {
            $this->startCheckBinding($pair);
        }

        $this->updateNominatedFlag($message, $pair);
    }

    /**
     * Finds an existing remote candidate or creates a peer-reflexive candidate.
     *
     * Maps an incoming message's source address to a known remote candidate or
     * creates a new peer-reflexive candidate if no match is found.
     *
     * @param MessageInterface $message The incoming STUN message
     * @param InternetAddress $address The source address
     * @param int $componentId The component ID
     * @return RTCIceCandidate The matching or newly created remote candidate
     * @throws RandomException If there's an error in random generation
     */
    private function findOrCreateRemoteCandidate(MessageInterface $message, InternetAddress $address, int $componentId): RTCIceCandidate
    {
        $host = $address->getAddress();
        $port = $address->getPort();
        foreach ($this->remoteCandidates as $candidate) {
            if (trim($candidate->getHost(), '[]') === $host && $candidate->getPort() === $port) {
                assert($candidate->getComponentId() === $componentId);
                return $candidate;
            }
        }

        return $this->createPeerReflexiveCandidate($message, $host, $port, $componentId);
    }

    /**
     * Creates a peer-reflexive candidate from an incoming connectivity check.
     *
     * Generates a new remote candidate with type "prflx" based on the source
     * address of an incoming connectivity check.
     *
     * @param MessageInterface $message The incoming STUN message
     * @param string $host The source host address
     * @param int $port The source port
     * @param int $componentId The component ID
     * @return RTCIceCandidate The newly created peer-reflexive candidate
     * @throws RandomException If there's an error in random generation
     */
    private function createPeerReflexiveCandidate(MessageInterface $message, string $host, int $port, int $componentId): RTCIceCandidate
    {
        $candidate = $this->createCandidate(Uuid::uuid4()->toString(), $host, $port, $componentId, CandidateType::prflx);
        /** @var int|null $priority */
        $priority = $message->attributes()->get(MessageAttribute::PRIORITY);
        if ($priority !== null) {
            $candidate->setPriority($priority);
        }
        $candidate->setFoundation(Utils::getRandomString(10));
        $this->remoteCandidates[] = $candidate;

        $this->logger?->info("Discovered peer reflexive candidate: " . $candidate->getHost());
        return $candidate;
    }

    /**
     * Creates a new candidate pair for connectivity checking.
     *
     * Forms a candidate pair from a local protocol and remote candidate,
     * adds it to the checklist, and sorts the checklist.
     *
     * @param IceConnectionProtocolInterface $protocol The local protocol
     * @param RTCIceCandidate $remoteCandidate The remote candidate
     * @return RTCIceCandidatePair The newly created candidate pair
     */
    private function createNewCandidatePair(IceConnectionProtocolInterface $protocol, RTCIceCandidate $remoteCandidate): RTCIceCandidatePair
    {
        $pair = new RTCIceCandidatePair($protocol, $remoteCandidate);
        $pair->setState(RTCIceCandidatePairStats::WAITING);
        $this->checkList[] = $pair;
        $this->sortCheckList();

        return $pair;
    }

    /**
     * Updates the nomination status of a candidate pair.
     *
     * Sets the nominated flag on a pair when the controlling agent indicates
     * a nomination through the USE_CANDIDATE attribute.
     *
     * @param MessageInterface $message The incoming STUN message
     * @param RTCIceCandidatePair $pair The candidate pair to potentially nominate
     * @return void
     */
    public function updateNominatedFlag(MessageInterface $message, RTCIceCandidatePair $pair): void
    {
        if ($message->attributes()->has(MessageAttribute::USE_CANDIDATE) && $this->isControlledRole()) {
            $pair->setRemoteNominated(true);

            if ($pair->getState() === RTCIceCandidatePairStats::SUCCEEDED) {
                $pair->setNominated(true);
                $this->completeCheckAction($pair);
                $this->scheduleBindingCheck();
            }
        }
    }

    /**
     * Processes a successful connectivity checks binding response.
     *
     * Handles a successful STUN binding response by validating the source address,
     * updating nomination status, and marking the pair as succeeded.
     *
     * @param InternetAddress $address The source address of the response
     * @param RTCIceCandidatePair $pair The candidate pair being checked
     * @param bool $nominate Whether the controlling agent is nominating this pair
     * @return void
     * @throws RandomException If there's an error in random generation
     */
    public function handleCheckBinding(InternetAddress $address, RTCIceCandidatePair $pair, bool $nominate): void
    {
        // Validate source address
        $remoteAddress = $pair->getRemoteAddress();
        if ($address->getAddress() !== $remoteAddress->getAddress() || $address->getPort() !== $remoteAddress->getPort()) {
            $this->logger?->debug("Check $pair failed: source address mismatch");
            $this->markPairFailed($pair);

            return;
        }

        // Mark as nominated if applicable
        if ($nominate || $pair->isRemoteNominated()) {
            $pair->setNominated(true);
        } elseif ($this->shouldNominate($pair)) {
            $this->performNomination($pair);

            return;
        }

        $this->markPairSucceeded($pair);
    }

    /**
     * Determines whether a candidate pair should be nominated.
     *
     * Checks if this agent is in the controlling role and if the component
     * has not yet been nominated.
     *
     * @param RTCIceCandidatePair $pair The candidate pair to evaluate
     * @return bool True if the pair should be nominated, false otherwise
     */
    private function shouldNominate(RTCIceCandidatePair $pair): bool
    {
        return $this->isControllingRole() && !in_array($pair->getComponentId(), $this->nominating);
    }

    /**
     * Initiates nomination of a candidate pair.
     *
     * Sends a STUN binding request with the USE_CANDIDATE attribute to
     * nominate a specific candidate pair for use.
     *
     * @param RTCIceCandidatePair $pair The candidate pair to nominate
     * @return void
     * @throws RandomException If there's an error in random generation
     */
    private function performNomination(RTCIceCandidatePair $pair): void
    {
        $this->logger?->info("Check $pair nominating pair");
        $this->nominating[] = $pair->getComponentId();
        $message = $this->buildBindingMessage($pair, true);
        $remoteAddress = $pair->getRemoteAddress();

        // The request blocks, so it runs in its own fiber: the caller drives the check list
        // and must not stall on one pair's transaction.
        async(function () use ($pair, $message, $remoteAddress): void {
            try {
                $pair->getProtocol()->request($message, $remoteAddress, $this->remotePassword);
                $pair->setNominated(true);
                $this->markPairSucceeded($pair);
            } catch (Throwable) {
                $this->logger?->info("Check $pair failed: could not nominate pair");
                $this->markPairFailed($pair);
            }
        })->ignore();
    }

    /**
     * Processes a failed connectivity check binding response.
     *
     * Handles error responses from STUN binding requests, including handling
     * role conflicts (487 error) by switching roles and retrying.
     *
     * @param TransactionExceptionInterface $e The exception containing error details
     * @param RTCIceCandidatePair $pair The candidate pair being checked
     * @param MessageInterface $message The original binding request message
     * @return void
     * @throws RandomException If there's an error in random generation
     */
    public function handleBindingError(TransactionExceptionInterface $e, RTCIceCandidatePair $pair, MessageInterface $message): void
    {
        $stunMessage = $e instanceof TransactionException ? $e->getStunMessage() : null;
        /** @var array{0: int, 1: string}|null $errorCode */
        $errorCode = $stunMessage?->attributes()->get(MessageAttribute::ERROR_CODE);

        if ($errorCode !== null && $errorCode[0] === 487) {
            $this->setRole(($message->attributes()->has(MessageAttribute::ICE_CONTROLLING) ? IceRole::Controlled : IceRole::Controlling));
            $this->startCheckBinding($pair); // Retry after switching roles
        } else {
            $this->markPairFailed($pair);
        }
    }

    /**
     * Initiates a connectivity check by sending a STUN binding request.
     *
     * Changes the candidate pair state to "in_progress" and sends a STUN binding
     * request to test connectivity, with appropriate handlers for success or failure.
     *
     * @param RTCIceCandidatePair $pair The candidate pair to check
     * @return void
     * @throws RandomException If there's an error in random generation
     */
    public function startCheckBinding(RTCIceCandidatePair $pair): void
    {
        $this->changeCandidatePairState($pair, RTCIceCandidatePairStats::IN_PROGRESS);
        $nominate = $this->isControllingRole() && !$this->remoteIsLite;
        $message = $this->buildBindingMessage($pair, $nominate);
        $remoteAddress = $pair->getRemoteAddress();

        // The request blocks, so it runs in its own fiber: several pairs are checked
        // concurrently and the check list has to keep moving while each is outstanding.
        async(function () use ($pair, $message, $remoteAddress, $nominate): void {
            try {
                [, $address] = $pair->getProtocol()->request($message, $remoteAddress, $this->remotePassword, self::CHECK_RETRANSMISSIONS);
                if ($address === null) {
                    $this->markPairFailed($pair);
                    return;
                }
                $this->handleCheckBinding($address, $pair, $nominate);
            } catch (TransactionExceptionInterface $e) {
                $this->handleBindingError($e, $pair, $message);
            } catch (Throwable $e) {
                // Anything else is a bug rather than a statement about this pair, but the
                // check list is driven by pairs reaching a terminal state: letting the fiber
                // die here would leave isBindingWait set and stall the whole exchange.
                $this->logger?->error("Check $pair aborted: {$e->getMessage()}");
                $this->markPairFailed($pair);
            }
        })->ignore();
    }

    /**
     * Creates a STUN binding request message for a connectivity check.
     *
     * Builds a STUN binding request with appropriate attributes for ICE connectivity
     * checking, including role information and nomination flags.
     *
     * @param RTCIceCandidatePair $pair The candidate pair for which to build the message
     * @param bool $nominate Whether to include the USE_CANDIDATE attribute for nomination
     * @return MessageInterface The constructed STUN binding request message
     * @throws RandomException If there's an error in random generation
     */
    public function buildBindingMessage(RTCIceCandidatePair $pair, bool $nominate): MessageInterface
    {
        $messageAttr = [
            MessageAttribute::USERNAME->name => sprintf("%s:%s", $this->remoteUsername ?? '', $this->localUsername),
            MessageAttribute::PRIORITY->name => $pair->getLocalCandidate()->getPriority(CandidateType::prflx->value)
        ];

        $message = Message::new(MessageClass::REQUEST, MessageMethod::BINDING, $messageAttr);

        if ($this->isControllingRole()) {
            $message->attributes()->add(MessageAttribute::ICE_CONTROLLING, $this->tieBreaker);

            if ($nominate) {
                $message->attributes()->add(MessageAttribute::USE_CANDIDATE);
            }
        } else {
            $message->attributes()->add(MessageAttribute::ICE_CONTROLLED, $this->tieBreaker);
        }

        return $message;
    }

    /**
     * Sets the ICE role (controlling or controlled) for this agent.
     *
     * Updates the role of this ICE agent and re-sorts the checklist based on the new role.
     *
     * @param IceRole $role The new role to set
     * @return void
     */
    private function setRole(IceRole $role): void
    {
        $this->logger?->info(sprintf("Switching ice role to %s.", $role->name));
        $this->iceRole = $role;
        $this->sortCheckList();
    }

    /**
     * Marks a candidate pair as failed.
     *
     * Updates the state of a candidate pair to "failed" and performs
     * post-failure actions.
     *
     * @param RTCIceCandidatePair $pair The candidate pair to mark as failed
     * @return void
     */
    private function markPairFailed(RTCIceCandidatePair $pair): void
    {
        $this->changeCandidatePairState($pair, RTCIceCandidatePairStats::FAILED);
        $this->completeCheckAction($pair);
        $this->tryFailedCount++;
        $this->isBindingWait = false;
        $this->scheduleBindingCheck();
    }

    /**
     * Marks a candidate pair as successfully connected.
     *
     * Updates the state of a candidate pair to "succeeded" and performs
     * post-success actions.
     *
     * @param RTCIceCandidatePair $pair The candidate pair to mark as succeeded
     * @return void
     */
    private function markPairSucceeded(RTCIceCandidatePair $pair): void
    {
        $this->changeCandidatePairState($pair, RTCIceCandidatePairStats::SUCCEEDED);
        $this->completeCheckAction($pair);
        $this->tryFailedCount = 0; // Reset failure count
        $this->isBindingWait = false;
        $this->scheduleBindingCheck();
    }

    /**
     * Initiates reactive binding-check processing.
     *
     * Checklist processing advances in response to candidate and pair state changes.
     *
     * @return Future<void> Completes once a candidate pair has been nominated.
     */
    private function bindCheck(): Future
    {
        /** @var DeferredFuture<void> */
        $this->bindingCheck = new DeferredFuture();
        $future = $this->bindingCheck->getFuture();
        $this->scheduleBindingCheck();

        return $future;
    }

    /**
     * Queues checklist progression once, coalescing simultaneous state changes.
     */
    private function scheduleBindingCheck(): void
    {
        if ($this->bindingCheck === null || $this->bindingCheckScheduled) {
            return;
        }

        $this->bindingCheckScheduled = true;
        EventLoop::queue(function (): void {
            $this->bindingCheckScheduled = false;
            $this->advanceBindingCheck();
        });
    }

    /**
     * Advances the checklist until a check is in flight or negotiation settles.
     */
    private function advanceBindingCheck(): void
    {
        if ($this->bindingCheck === null) {
            return;
        }

        if (isset($this->checkListState) && $this->checkListState === CheckListState::ICE_COMPLETED) {
            $this->settleBindingCheck();
            return;
        }

        if ((isset($this->checkListState) && $this->checkListState === CheckListState::ICE_FAILED)
            || $this->tryFailedCount > self::RETRY_BINDING_MAX
        ) {
            $this->settleBindingCheck(new RuntimeException("Binding check failed"));
            return;
        }

        if ($this->isBindingWait) {
            return;
        }

        $this->isBindingWait = true;
        if ($this->tryCheckBinding()) {
            $this->settleBindingCheck();
            return;
        }

        if ($this->tryFailedCount > self::RETRY_BINDING_MAX) {
            $this->settleBindingCheck(new RuntimeException("Binding check failed"));
        }
    }

    /**
     * Completes the active binding check exactly once.
     */
    private function settleBindingCheck(?Throwable $error = null): void
    {
        $bindingCheck = $this->bindingCheck;
        if ($bindingCheck === null) {
            return;
        }

        $this->bindingCheck = null;
        $this->isBindingWait = false;

        if ($error === null) {
            $bindingCheck->complete();
        } else {
            $bindingCheck->error($error);
        }
    }

    /**
     * Attempts to find and start checking a candidate pair.
     *
     * Looks for pairs in the "waiting" or "frozen" states to begin connectivity
     * checks on, or determine if the ICE process has completed or failed.
     *
     * @return bool True if ICE has completed, false if checks are still ongoing
     */
    private function tryCheckBinding(): bool
    {
        if (array_any([RTCIceCandidatePairStats::WAITING, RTCIceCandidatePairStats::FROZEN], fn($state) => $this->tryCheckBindingByPairState($state))) {
            return false;
        }

        // Nothing was started, so no check is outstanding. The flag means "a check is in
        // flight" and is otherwise only cleared when a pair reaches a terminal state, so
        // leaving it set here makes every later tick return early and the exchange stalls.
        $this->isBindingWait = false;

        if (empty($this->checkList)) {
            if ($this->remoteCandidatesEnd) {
                $this->tryFailedCount = self::RETRY_BINDING_MAX + 1;
            }
            return false;
        }

        // A controlled agent with a valid pair waits reactively for USE-CANDIDATE.
        if ($this->isControlledRole() && $this->hasAnySucceededPair()) {
            return false;
        }

        if ($this->remoteCandidatesEnd) {
            $this->tryFailedCount = self::RETRY_BINDING_MAX + 1;
        }

        return $this->remoteCandidatesEnd && $this->checkListDone;
    }

    /**
     * Attempts to find and check pairs in a specific state.
     *
     * Looks for candidate pairs in a given state and attempt to start
     * connectivity checks on them.
     *
     * @param RTCIceCandidatePairStats $state The state to look for
     * @return bool True if a pair was found and checking started, false otherwise
     */
    private function tryCheckBindingByPairState(RTCIceCandidatePairStats $state): bool
    {
        return array_any($this->checkList, fn($pair) => $pair->getState() === $state && $this->tryStartCheckBinding($pair));
    }

    /**
     * Attempts to start connectivity checking on a candidate pair.
     *
     * Tries to initiate a connectivity check on a specific candidate pair,
     * handling any exceptions that may occur.
     *
     * @param RTCIceCandidatePair $pair The candidate pair to check
     * @return bool True if the check was started successfully, false otherwise
     */
    private function tryStartCheckBinding(RTCIceCandidatePair $pair): bool
    {
        try {
            $this->startCheckBinding($pair);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Processes the completion of a connectivity check.
     *
     * Handles actions after a connectivity check is completed, including
     * updating the checklist state and potentially finalizing the ICE process.
     *
     * @param RTCIceCandidatePair $pair The candidate pair that completed checking
     * @return void
     */
    #[Override]
    public function completeCheckAction(RTCIceCandidatePair $pair): void
    {
        if ($pair->getState() === RTCIceCandidatePairStats::SUCCEEDED) {
            if ($this->handleSuccessfulPair($pair)) {
                return;
            }
        }

        if ($this->checkListHasPendingPairs()) {
            return;
        }

        if ($this->isControlledRole() && $this->hasAnySucceededPair()) {
            return;
        }

        $this->finalizeChecklistState();
    }

    /**
     * Processes a successfully checked candidate pair.
     *
     * Updates the connection state based on a successful connectivity check,
     * including a handling nomination and unfreezing related pairs.
     *
     * @param RTCIceCandidatePair $pair The successfully checked candidate pair
     * @return bool True if ICE has completed, false otherwise
     */
    private function handleSuccessfulPair(RTCIceCandidatePair $pair): bool
    {
        if ($pair->isNominated()) {
            $this->nominated[$pair->getComponentId()] = $pair;
            $this->failOtherPairsInComponent($pair);
        }

        if ($this->checkIfICECompleted()) {
            $this->suspendUnusedResource();

            return true;
        }

        $this->updateFrozenPairs($pair);

        return false;
    }

    /**
     * Fails all other pairs for a component once one pair is nominated.
     *
     * Marks waiting or frozen pairs as failed when another pair in the same
     * component has been nominated.
     *
     * @param RTCIceCandidatePair $pair The nominated pair
     * @return void
     */
    private function failOtherPairsInComponent(RTCIceCandidatePair $pair): void
    {
        foreach ($this->checkList as $p) {
            if ($p->getComponentId() === $pair->getComponentId() &&
                in_array($p->getState(), [RTCIceCandidatePairStats::WAITING, RTCIceCandidatePairStats::FROZEN])) {
                $this->changeCandidatePairState($p, RTCIceCandidatePairStats::FAILED);
            }
        }
    }

    /**
     * Checks if the ICE process has completed successfully.
     *
     * Determines if all components have nominated pairs, indicating that
     * ICE connectivity establishment has completed successfully.
     *
     * @return bool True if ICE has completed, false otherwise
     */
    private function checkIfICECompleted(): bool
    {
        if (count($this->nominated) === count($this->componentIds)) {
            if (!$this->checkListDone) {
                $this->logger?->info("ICE completed");
                $this->checkListState = CheckListState::ICE_COMPLETED;
                $this->checkListDone = true;
            }

            return true;
        }

        return false;
    }

    /**
     * Updates frozen pairs with the same foundation as a successful pair.
     *
     * Changes the state of frozen pairs with the same foundation as a successful
     * pair from "frozen" to "waiting".
     *
     * @param RTCIceCandidatePair $pair The successfully checked pair
     * @return void
     */
    private function updateFrozenPairs(RTCIceCandidatePair $pair): void
    {
        foreach ($this->checkList as $p) {
            if ($p->getLocalCandidate()->getFoundation() === $pair->getLocalCandidate()->getFoundation() &&
                $p->getState() === RTCIceCandidatePairStats::FROZEN) {
                $this->changeCandidatePairState($p, RTCIceCandidatePairStats::WAITING);
            }
        }
    }

    /**
     * Checks if the checklist contains any pairs in a pending state.
     *
     * Determines if any candidate pairs are still in a state other than
     * "succeeded" or "failed".
     *
     * @return bool True if there are pending pairs, false otherwise
     */
    private function checkListHasPendingPairs(): bool
    {
        return array_any($this->checkList, fn($p) => !in_array($p->getState(), [RTCIceCandidatePairStats::SUCCEEDED, RTCIceCandidatePairStats::FAILED]));
    }

    /**
     * Checks if any candidate pair has succeeded.
     *
     * Determines if at least one candidate pair has successfully completed
     * connectivity checks.
     *
     * @return bool True if any pair has succeeded, false otherwise
     */
    private function hasAnySucceededPair(): bool
    {
        return array_any($this->checkList, fn($p) => $p->getState() === RTCIceCandidatePairStats::SUCCEEDED);
    }

    /**
     * Finalizes the checklist state when all checks are complete.
     *
     * Sets the checklist state to fail if all checks have completed
     * without finding valid connectivity.
     *
     * @return void
     */
    private function finalizeChecklistState(): void
    {
        $this->tryFailedCount++;
        if (!$this->checkListDone) {
            $this->logger?->error("ICE failed");
            $this->checkListState = CheckListState::ICE_FAILED;
            $this->checkListDone = true;
        }
    }

    /**
     * Periodically performs consent freshness checks as per RFC 7675.
     *
     * Sends periodic STUN binding requests on all nominated candidate pairs to confirm
     * that the remote peer still consents to receive traffic. If repeated failures occur,
     * the connection is closed.
     *
     * @see https://www.rfc-editor.org/rfc/rfc7675
     *
     * @return void
     */
    #[Override]
    public function periodicConsentCheck(): void
    {
        $interval = $this->calculateConsentInterval();
        $failureCount = 0;

        $this->queryConsentTimer = EventLoop::repeat($interval, function () use (&$failureCount): void {
            foreach ($this->nominated as $pair) {
                $message = $this->buildBindingMessage($pair, false);
                $remoteAddress = $pair->getRemoteAddress();

                // Each check blocks, and a repeat callback that suspends is not re-entered,
                // so the checks run in their own fibers.
                async(function () use ($pair, $message, $remoteAddress, &$failureCount): void {
                    try {
                        $pair->getProtocol()->request($message, $remoteAddress, $this->remotePassword);
                        $failureCount = 0; // Reset failures on success
                    } catch (Throwable $e) {
                        $failureCount++;
                        $this->logger?->warning("Consent check failed for pair: $pair. Error: {$e->getMessage()}");
                        if ($failureCount >= self::CONSENT_FAILURES) {
                            $this->logger?->error("Consent to send expired after $failureCount failures");
                            $this->close();
                        }
                    }
                })->ignore();
            }
        });
    }

    /**
     * Calculates a randomized interval for consent freshness checks.
     *
     * This interval is based on a multiplier of a fixed base interval,
     * randomized within a range as defined in RFC 7675, section 5.1.
     *
     * @return float The randomized interval in seconds.
     */
    private function calculateConsentInterval(): float
    {
        // See https://www.rfc-editor.org/rfc/rfc7675#section-5.1
        return (float)self::CONSENT_INTERVAL * (0.8 + 0.4 * (float)mt_rand() / (float)mt_getrandmax());
    }

    /**
     * Closes the ICE connection and releases all associated resources.
     *
     * Stops timers, clears protocol and candidate references,
     * marks the checklist as failed, and emits an 'onClose' event.
     *
     * @return void
     * @throws RandomException
     *
     * @throws Throwable
     */
    #[Override]
    public function close(): void
    {
        $this->stopPeriodicConsentCheck();
        $this->markCheckListAsFailed();
        $this->clearResources();
        $this->emit('onClose');
        $this->removeAllListeners();
        $this->settleBindingCheck(new RuntimeException("Binding check failed"));

        if (!$this->closed) {
            $this->closed = true;
            $this->logger?->info("ICE connection closed");
        }
    }

    /**
     * Stops the timer responsible for periodic consent checks.
     *
     * If the timer is active, it will be canceled and removed.
     *
     * @return void
     */
    private function stopPeriodicConsentCheck(): void
    {
        if ($this->queryConsentTimer !== null) {
            EventLoop::cancel($this->queryConsentTimer);
            $this->queryConsentTimer = null;
        }
    }

    /**
     * Marks the connection's checklist as failed if it has not already been processed.
     *
     * This indicates that ICE negotiation has failed.
     *
     * @return void
     */
    private function markCheckListAsFailed(): void
    {
        if (!empty($this->checkList) && !$this->checkListDone) {
            $this->checkListState = CheckListState::ICE_FAILED;
        }
    }

    /**
     * Releases all protocol and candidate resources associated with this ICE connection.
     *
     * Iterates through all protocols and closes/deletes them safely.
     *
     * @return void
     * @throws RandomException
     *
     * @throws Throwable
     */
    private function clearResources(): void
    {
        foreach ($this->protocols as $protocol) {
            if ($protocol instanceof Turn) {
                @$protocol->delete();
            }
            @$protocol->close();
        }

        $this->nominated = [];
        $this->protocols = [];
        $this->localCandidates = [];
    }

    /**
     * Sends application data over the nominated candidate pair for a specific component.
     *
     * @param string $data The data to send.
     * @param int $componentId The component ID (default is 1).
     *
     * @return void
     * @throws RuntimeException If no nominated pair exists for the given component.
     *
     */
    #[Override]
    public function sendData(string $data, int $componentId = 1): void
    {
        if (isset($this->nominated[$componentId])) {
            $pair = $this->nominated[$componentId];
            $pair->getProtocol()->send($data, $pair->getRemoteAddress());
        } else {
            throw new RuntimeException("No Connection");
        }
    }

    /**
     * Handles incoming application data.
     *
     * Emits a 'data' event containing the received data and component ID.
     *
     * @param string $data The received data.
     * @param int $componentId The ID of the receiving component.
     *
     * @return void
     */
    #[Override]
    public function onDataReceived(string $data, int $componentId): void
    {
        $this->emit("data", [$data, $componentId]);
    }

    /**
     * Handles an incoming STUN binding request.
     *
     * Validates and responds to the request, handling role conflicts and authentication.
     * Also processes early or ongoing connectivity checks.
     *
     * @param MessageInterface $message The incoming STUN message.
     * @param InternetAddress $address The address from which the message was received.
     * @param IceConnectionProtocolInterface $protocol The protocol used for this connection.
     * @param string $data The raw received data.
     *
     * @return void
     * @throws RandomException
     *
     */
    #[Override]
    public function onRequestReceived(MessageInterface $message, InternetAddress $address, IceConnectionProtocolInterface $protocol, string $data): void
    {
        if (!$this->isBindingRequest($message) || !$this->authenticateRequest($message, $data)) {
            $this->respondError($message, $address, $protocol, [400, "Bad Request"]);

            return;
        }

        if ($this->handleRoleConflict($message, $address, $protocol)) {
            return;
        }

        // Send success binding response
        $this->sendBindingResponse($message, $address, $protocol);
        if (empty($this->checkList) && !$this->earlyChecksDone) {
            $this->earlyChecks[] = [$message, $address, $protocol];
        } else {
            $this->checkIncoming($message, $address, $protocol);
        }
    }

    /**
     * Determines if a given message is a STUN binding request.
     *
     * @param MessageInterface $message The STUN message to evaluate.
     *
     * @return bool True if it's a binding request; false otherwise.
     */
    public function isBindingRequest(MessageInterface $message): bool
    {
        return $message->getMessageMethod() === MessageMethod::BINDING;
    }

    /**
     * Validates the authentication of a STUN binding request.
     *
     * Checks message integrity and username attributes to ensure authenticity.
     *
     * @param MessageInterface $message The STUN message to validate.
     * @param string $data The raw STUN message data.
     *
     * @return bool True if authenticated; false otherwise.
     */
    public function authenticateRequest(MessageInterface $message, string $data): bool
    {
        try {
            Message::decode($data, $this->localPassword);

            if ($this->remoteUsername !== null) {
                $expectedUsername = sprintf("%s:%s", $this->localUsername, $this->remoteUsername);
                return $message->attributes()->get(MessageAttribute::USERNAME) === $expectedUsername;
            }

            return true;
        } catch (Throwable $e) {
            $this->logger?->error("Authentication failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Handles ICE role conflict resolution between peers.
     *
     * If a role conflict is detected, either resolves the conflict or sends a role conflict error.
     *
     * @param MessageInterface $message The incoming message.
     * @param InternetAddress $address The sender address.
     * @param IceConnectionProtocolInterface $protocol The associated protocol.
     *
     * @return bool True, if a conflict was handled and the message shouldn’t be processed further.
     * @throws RandomException
     *
     */
    public function handleRoleConflict(MessageInterface $message, InternetAddress $address, IceConnectionProtocolInterface $protocol): bool
    {
        $attributes = $message->attributes();

        if ($this->isControllingRole() && $attributes->has(MessageAttribute::ICE_CONTROLLING)) {
            $this->logger?->info("Role conflict detected: expected controlling role.");
            /** @var int|null $controlling */
            $controlling = $attributes->get(MessageAttribute::ICE_CONTROLLING);
            if (is_int($controlling) && $this->compareTieBreaker($controlling) >= 0) {
                $this->respondError($message, $address, $protocol, [487, "Role Conflict"]);

                return true;
            }

            $this->setRole(IceRole::Controlled);
        } elseif ($this->isControlledRole() && $attributes->has(MessageAttribute::ICE_CONTROLLED)) {
            $this->logger?->info("Role conflict detected: expected controlled role.");
            /** @var int|null $controlled */
            $controlled = $attributes->get(MessageAttribute::ICE_CONTROLLED);
            if (is_int($controlled) && $this->compareTieBreaker($controlled) < 0) {
                $this->respondError($message, $address, $protocol, [487, "Role Conflict"]);

                return true;
            }

            $this->setRole(IceRole::Controlling);
        }

        return false;
    }

    private function compareTieBreaker(int $remoteTieBreaker): int
    {
        return ($this->tieBreaker ^ PHP_INT_MIN) <=> ($remoteTieBreaker ^ PHP_INT_MIN);
    }

    /**
     * Sends a STUN binding success response to a validated request.
     *
     * Adds the necessary attributes and message integrity, then sends the response asynchronously.
     *
     * @param MessageInterface $message The original binding request.
     * @param InternetAddress $address The destination address.
     * @param IceConnectionProtocolInterface $protocol The protocol to use for sending.
     *
     * @return void
     * @throws RandomException
     *
     */
    public function sendBindingResponse(MessageInterface $message, InternetAddress $address, IceConnectionProtocolInterface $protocol): void
    {
        $responseAttributes = [MessageAttribute::XOR_MAPPED_ADDRESS->name => $address];
        $responseMessage = Message::new(MessageClass::RESPONSE, MessageMethod::BINDING, $responseAttributes);
        $responseMessage->setTransactionId($message->getTransactionId());
        $responseMessage->addMessageIntegrity($this->localPassword);

        async(function () use ($protocol, $responseMessage, $address) {
            try {
                $response = $protocol->request($responseMessage, $address, null);
                $this->logger?->info("Binding response sent successfully", [
                    "Message" => $response[0]->humanReadable(),
                    "Address" => $response[1]
                ]);
            } catch (TransactionExceptionInterface $e) {
                $this->logger?->error("Failed to send binding response", ["Error" => $e->getMessage()]);
            }
        })->ignore();
    }

    /**
     * Sends a STUN error response to an invalid or unauthenticated request.
     *
     * Constructs and sends a binding error message using the given error code.
     *
     * @param MessageInterface $orgMessage The original message to respond to.
     * @param InternetAddress $address The address to send the error to.
     * @param IceConnectionProtocolInterface $protocol The protocol used for transmission.
     * @param array $errorCode The error code and reason [code, reason].
     *
     * @return void
     * @throws RandomException
     *
     */
    #[Override]
    public function respondError(MessageInterface $orgMessage, InternetAddress $address, IceConnectionProtocolInterface $protocol, array $errorCode): void
    {
        $messageAttr = [MessageAttribute::ERROR_CODE->name => $errorCode];
        $message = Message::new(MessageClass::ERROR, $orgMessage->getMessageMethod(), $messageAttr);
        $message->setTransactionId($orgMessage->getTransactionId());
        $message->addMessageIntegrity($this->localPassword);

        $protocol->sendMessage($message, $address);
    }

    /**
     * Checks whether the local agent is currently in the controlling role.
     *
     * @return bool True if in controlling role, false otherwise.
     */
    public function isControllingRole(): bool
    {
        return $this->iceRole === IceRole::Controlling;
    }

    /**
     * Checks whether the local agent is currently in the controlled role.
     *
     * @return bool True if in controlled role, false otherwise.
     */
    private function isControlledRole(): bool
    {
        return $this->iceRole === IceRole::Controlled;
    }

    /**
     * Emits the 'onClose' event to signal ICE connection closure.
     *
     * @return void
     */
    #[Override]
    public function onClose(): void
    {
        $this->emit("onClose");
    }

    /**
     * Emits an 'onError' event when an error occurs.
     *
     * @param Throwable $e The exception or error encountered.
     *
     * @return void
     */
    #[Override]
    public function onError(Throwable $e): void
    {
        $this->emit("onError", [$e]);
    }

    /**
     * Suspends unused transport resources that are not part of nominated candidate pairs.
     *
     * Intended to optimize resource usage by pausing inactive transports.
     * Currently disabled (FixMe).
     *
     * @return void
     */
    private function suspendUnusedResource(): void
    {
        // FixMe: it closes all resources
//        $unUsedProtocols = array_udiff($this->protocols, $this->nominated, function (Stun|Turn $protocol, RTCIceCandidatePair $pair) {
//            return strcmp($protocol->getId(), $pair->getProtocol()->getId());
//        });
//
//        foreach ($unUsedProtocols as $protocol) {
//            $protocol->pause();
//        }
    }

    /**
     * Returns the current ICE role (Controlling or Controlled).
     *
     * @return IceRole The current ICE role.
     */
    #[Override]
    public function getIceRole(): IceRole
    {
        return $this->iceRole;
    }

    /**
     * Checks if the ICE connection is closed.
     *
     * @return bool True if closed, false otherwise.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Gets the default local candidate for a specific component.
     *
     * @param int $component The component ID.
     *
     * @return RTCIceCandidate|null The candidate with the highest priority, or null if not found.
     */
    public function getDefaultCandidate(int $component): ?RTCIceCandidate

    {
        $sortedCandidates = $this->localCandidates;
        usort($sortedCandidates, fn(RTCIceCandidate $a, RTCIceCandidate $b) => $a->getPriority() <=> $b->getPriority());

        foreach ($sortedCandidates as $candidate) {
            if ($candidate->getComponentId() === $component) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Returns the current ICE checklist (candidate pairs to check).
     *
     * @return array The checklist array.
     */
    public function getCheckList(): array
    {
        return $this->checkList;
    }

    /**
     * Sets the flag indicating whether remote candidates have finished being provided.
     *
     * @param bool $remoteCandidatesEnd True if all remote candidates are gathered.
     *
     * @return void
     */
    public function setRemoteCandidatesEnd(bool $remoteCandidatesEnd): void
    {
        $this->remoteCandidatesEnd = $remoteCandidatesEnd;
        $this->scheduleBindingCheck();
    }

    /**
     * Sets the tie-breaker value used during ICE role conflict resolution.
     *
     * @param int $tieBreaker A unique 64-bit tiebreaker value.
     *
     * @return void
     */
    public function setTieBreaker(int $tieBreaker): void
    {
        $this->tieBreaker = $tieBreaker;
    }

    /**
     * Sets the consent check timer (only for testing purposes).
     *
     * @param string|null $queryConsentTimer The timer handle or null to unset.
     *
     * @return void
     */
    public function setQueryConsentTimer(?string $queryConsentTimer): void
    {
        $this->queryConsentTimer = $queryConsentTimer;
    }

    /**
     * Gets the currently nominated candidate pairs.
     *
     * @return array The nominated candidate pairs.
     */
    public function getNominated(): array
    {
        return $this->nominated;
    }

    /**
     * Checks whether the end-of-candidates signal has been received from the remote peer.
     *
     * @return bool True if no more remote candidates are expected.
     */
    #[Override]
    public function isRemoteCandidatesEnd(): bool
    {
        return $this->remoteCandidatesEnd;
    }

    /**
     * Sets the transport policy for the ICE connection.
     *
     * Validates that required servers are configured for the RELAY-only policy.
     *
     * @param TransportPolicyType $transportPolicy The desired transport policy.
     *
     * @return void
     * @throws InvalidArgumentException If required, servers are missing.
     *
     */
    public function setTransportPolicy(TransportPolicyType $transportPolicy): void
    {
        if ($this->configuration->getStunServer() === null
            && $this->configuration->getTurnServer() === null
            && $transportPolicy === TransportPolicyType::RELAY) {
            throw new InvalidArgumentException("Relay transport policy requires a STUN and/or TURN server.");
        }

        $this->transportPolicy = $transportPolicy;
    }

    /**
     * Sets the current ICE role (Controlling or Controlled).
     *
     * @param IceRole $iceRole The ICE role to set.
     *
     * @return void
     */
    #[Override]
    public function setIceRole(IceRole $iceRole): void

    {
        $this->iceRole = $iceRole;
    }

    public function setIcePortRange(?array $icePortRange): void
    {
        $this->icePortRange = $icePortRange;
    }

    /**
     * @return array<int, string>|null
     */
    public function getNat1to1(): ?array
    {
        return $this->nat1to1;
    }

    /**
     * @param array<int, string>|null $nat1to1
     */
    public function setNat1to1(?array $nat1to1): void
    {
        $this->nat1to1 = $nat1to1;
    }
}
