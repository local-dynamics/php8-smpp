# php8-smpp
SMPP Client (v 3.4) on PHP 8

[![phpstan](https://badgen.net/github/checks/php8-smpp/php8-smpp/main/PHPStan)]()
[![phpunit 8.2](https://badgen.net/github/checks/php8-smpp/php8-smpp/main/PHPUnit%20%28PHP%208.2%29)]()
[![phpunit 8.3](https://badgen.net/github/checks/php8-smpp/php8-smpp/main/PHPUnit%20%28PHP%208.3%29)]()
[![stars](https://badgen.net/github/stars/php8-smpp/php8-smpp/)]()
[![forks](https://badgen.net/github/forks/php8-smpp/php8-smpp/)]()
[![open-issues](https://badgen.net/github/open-issues/php8-smpp/php8-smpp/)]()

A modern, strictly-typed SMPP v3.4 client for PHP 8. It sends and receives SMS
through an SMSC, encodes UTF-8 to GSM 03.38, and wraps PHP sockets with
connection-pool, timeout and IPv6 support.

This is a fork of [**php8-smpp/php8-smpp**](https://github.com/php8-smpp/php8-smpp)
that adds an asynchronous windowed submit API plus a batch of reliability and
hardening fixes — see [Changes vs. upstream](#changes-vs-php8-smppphp8-smpp) below.

## Project Status: Stable API (v0.1)
### Refactoring is nearly complete – no further breaking changes are anticipated.

### Current focus:
- ✅ Writing comprehensive tests
- ✅ Adding usage examples & documentation
- ✅ Expanding functionality (backward-compatible)

We welcome:

- Bug reports
- Documentation improvements
- Feature suggestions (via Issues)

## Changes vs. `php8-smpp/php8-smpp`

This fork branches from upstream and adds the following.

### New feature: windowed / asynchronous submit API

`WindowedClient` adds a non-blocking, windowed `submit_sm` API on top of the
synchronous `Client`, so a long-running sender can keep many messages in flight
instead of blocking on each `submit_sm_resp` — designed to keep throughput high
on high-RTT links.

- Fire messages with `submitAsync()` (bounded by `canSubmit()` / `pendingCount()`),
  then `pump()` to drain responses, match them by sequence number and surface
  completed logical (multi-segment CSMS) messages, timeouts and inbound SMS.
- Multi-segment CSMS messages only complete once **all** segments are acked;
  overflow of a multi-segment message is rejected atomically.
- Configurable via `SmppConfig::setWindowSize()` and `setWindowTimeoutMs()`.
- Implemented as value objects / a state machine: `InFlightWindow`,
  `InFlightGroup`, `InFlightSegment`, `SubmitResult`, `PumpResult`.
- `Client` was refactored (extracted `buildSubmitSmBody` / `buildSubmitSmSegments`
  / `isMessageSendable`, `enqueuePdu` widened to `protected`) to support this
  without breaking the synchronous API.

### Reliability & hardening fixes

- **Daemon receive-loop stabilization** — fixes silent hangs in the read
  strategies (`recv() === 0` handling in `NonBlockingReadStrategy`/
  `BlockingReadStrategy`, retry decorator, closed-transport guarding).
- **PDU parser bounds** — buffer-bounds checks in `PDUParser` to reject
  truncated / malformed PDUs instead of reading out of range.
- **PDU queue limit** — cap the internal inbound PDU queue to bound memory.
- **Socket close handling** — `ClosedTransportException` and cleaner
  `SocketTransport` close semantics.
- **GSM encoder Cyrillic fix** — correct UCS-2 fallback for non-GSM (e.g.
  Cyrillic) text in `GsmEncoderHelper`.
- **Input validation** — stricter network/DSN input validation, address-range
  max-length enforcement, socket-config IP exclusivity, `ClientBuilder`
  credentials guard.
- **Config setter validation** — validated `SmppConfig` setters and a fixed
  `systemType` default.
- **Exception hierarchy** — tightened `SmppException` / `SocketTransportException`
  relationships and a `RetryableExceptionInterface` marker for retry logic.
- **Logger binding & PHP 8.4 compatibility** — logger passed by reference,
  implicit-nullable parameter fixes.

### Tooling

- Requires **PHP 8.2+**, upgraded PHPStan to 1.12 (clean at the configured level).
- Extensive added test coverage (windowing, parser bounds, read strategies,
  validators, config, exception hierarchy, …).

### Carried over from upstream `php8-smpp`

For reference, the upstream project already provides: DSN-based connection
configuration, a `ClientBuilder` factory, pluggable read strategies
(`Blocking`/`NonBlocking`/`Hybrid`), a `RetryableReadDecorator`, middleware
support, PSR-3 logging, typed config objects, input validators, an SCTP
transport and a structured exception hierarchy.

## Install
```shell
composer require php8-smpp/php8-smpp
```

## Documentation

Usage examples:
- 00. About DSN — [en](/docs/en/00-about-dsn.md)
- 01. Simple client building — [en](/docs/examples/basic-usage/en/01-default-client.md)
- 02. Read strategies — [en](/docs/examples/basic-usage/en/02-read-strategies-in-socket-transport.md)
- 03. Alternative client factory — [en](/docs/examples/basic-usage/en/03-alternative-client-factory.md)

### About the protocol

[SMPP v3.4 specification](https://smpp.org/SMPP_v3_4_Issue1_2.pdf)

### Legacy

[Legacy (original) README](/docs/original_README.md)

## Credits & Original Repository

This project is a fork of [**php8-smpp/php8-smpp**](https://github.com/php8-smpp/php8-smpp),
which itself descends from [alexandr-mironov/php-smpp](https://github.com/alexandr-mironov/php-smpp)
and the original [onlinecity/php-smpp](https://github.com/onlinecity/php-smpp).
Please refer to the upstream repository for the base implementation and history.
