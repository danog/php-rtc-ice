// Command turnserver is the STUN/TURN server the ICE test suite spawns.
//
// It replaces coturn: coturn's Windows build is a cygwin build whose per-CPU-core
// "UDP thread per CPU core" listener model binds several UDP sockets to the same port with
// SO_REUSEPORT, which cygwin/Windows does not honour, so every UDP listener fails to bind and
// the server serves no STUN/TURN over UDP. This pion/turn server binds a single UDP socket (and
// a TCP one for TURN-over-TCP) and works identically on every platform, so the suite uses it
// everywhere.
//
// It answers STUN Binding requests (for server-reflexive candidates) and TURN allocations over
// UDP and TCP (for relay candidates), authenticating with the long-term credentials the tests
// configure (username "quasarstream", password "123", realm "quasarstream.com").
package main

import (
	"fmt"
	"net"
	"os"
	"strconv"

	"github.com/pion/turn/v4"
)

func main() {
	const (
		listenIP = "127.0.0.1"
		realm    = "quasarstream.com"
		username = "quasarstream"
		password = "123"
	)

	port := 3478
	if v := os.Getenv("PHP_RTC_TURN_PORT"); v != "" {
		if p, err := strconv.Atoi(v); err == nil {
			port = p
		}
	}
	addr := net.JoinHostPort(listenIP, strconv.Itoa(port))

	authKey := turn.GenerateAuthKey(username, realm, password)
	authHandler := func(u, _ string, _ net.Addr) ([]byte, bool) {
		if u == username {
			return authKey, true
		}
		return nil, false
	}

	relayIP := net.ParseIP(listenIP)
	newGenerator := func() turn.RelayAddressGenerator {
		return &turn.RelayAddressGeneratorStatic{RelayAddress: relayIP, Address: listenIP}
	}

	udpListener, err := net.ListenPacket("udp4", addr)
	if err != nil {
		fmt.Fprintf(os.Stderr, "turnserver: cannot listen udp %s: %v\n", addr, err)
		os.Exit(1)
	}
	tcpListener, err := net.Listen("tcp4", addr)
	if err != nil {
		fmt.Fprintf(os.Stderr, "turnserver: cannot listen tcp %s: %v\n", addr, err)
		os.Exit(1)
	}

	server, err := turn.NewServer(turn.ServerConfig{
		Realm:       realm,
		AuthHandler: authHandler,
		PacketConnConfigs: []turn.PacketConnConfig{
			{PacketConn: udpListener, RelayAddressGenerator: newGenerator()},
		},
		ListenerConfigs: []turn.ListenerConfig{
			{Listener: tcpListener, RelayAddressGenerator: newGenerator()},
		},
	})
	if err != nil {
		fmt.Fprintf(os.Stderr, "turnserver: %v\n", err)
		os.Exit(1)
	}
	defer func() { _ = server.Close() }()

	fmt.Fprintf(os.Stderr, "turnserver: STUN/TURN listening on %s (udp+tcp), realm %s\n", addr, realm)

	select {} // Block forever; the test suite owns the process and terminates it.
}
