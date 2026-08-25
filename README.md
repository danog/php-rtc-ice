# ICE

[![PHP Version](https://img.shields.io/badge/php-8.2%2B-blue.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-BSD-blue.svg)](LICENSE)

A PHP implementation of the [Interactive Connectivity Establishment (ICE)](https://tools.ietf.org/html/rfc5245) protocol for WebRTC. This library helps establish peer-to-peer connectivity by handling candidate gathering, prioritization, connectivity checks, and consent freshness.

## About this fork

This is the `danog/php-rtc-ice` fork used by MadelineProto. It targets PHP 8.2+ and replaces the ReactPHP implementation with Amp v3 DNS, sockets, fibers, and Revolt timers. Blocking connectivity checks run in separate fibers so socket receive loops remain live.

The forked stack keeps the upstream `quasarstream/*` dependency constraints for compatibility. Each `danog/php-rtc-*` package replaces its upstream counterpart, so consumers select the complete maintained stack by requiring the corresponding danog packages together.

## Features

- STUN and TURN support (RFC 5389 / RFC 5766)
- Host, server-reflexive, relayed, and peer-reflexive candidates
- Candidate prioritization and pair selection
- ICE connectivity checks using STUN Binding Requests
- Consent freshness mechanism
- ICE Lite mode (optional)
- IPv4 and IPv6 support
## Requirements

- **PHP ≥ 8.2**

## Documentation

This package is part of the PHP WebRTC library. For complete documentation, examples, and API reference, visit:

[PHP WebRTC Documentation](https://www.quasarstream.com/php-webrtc)

## Credits

### Authors

- **Amin Yazdanpanah**  
  - Website: [aminyazdanpanah.com](https://www.aminyazdanpanah.com)
  - Email: [github@aminyazdanpanah.com](mailto:github@aminyazdanpanah.com)

- **Sana Moniri**  
  - GtiHub: [sanamoniri](https://github.com/sanamoniri)

## Reporting Issues

Found a bug? Please report it on our [issues](https://github.com/php-webrtc/ICE/issues).

## License

BSD 3-Clause License. See [LICENSE](LICENSE) for details.
