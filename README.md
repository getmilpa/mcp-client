<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa MCP Client

> An **MCP (Model Context Protocol) client** for PHP — stdio and HTTP/SSE transports, typed tool/resource contracts, and connection management for talking to external MCP servers. `McpClientManager` connects to several servers at once and routes tool calls to the right one by a namespaced registry name.

[![CI](https://github.com/getmilpa/mcp-client/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/mcp-client/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/mcp-client.svg)](https://packagist.org/packages/milpa/mcp-client)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-API%20reference-blue.svg)](https://getmilpa.github.io/mcp-client/)

`milpa/mcp-client` is the *outbound* half of MCP for the Milpa framework: it lets a PHP
process act as an **MCP client**, connecting to one or more external MCP servers — local
ones spawned as subprocesses (stdio) or remote ones over HTTP with Server-Sent Events —
and discovering, listing, and calling the tools and resources they expose. **No product
coupling**: its only production dependency is `guzzlehttp/guzzle`, for the HTTP/SSE
transport.

## Install

```bash
composer require milpa/mcp-client
```

## Quick example

Register a server, connect, and call one of its tools. `McpClientManager` picks the
right `TransportInterface` from the `transport` key in the config you pass it:

```php
use Milpa\McpClient\McpClientManager;

$manager = new McpClientManager();

$manager->registerServer('calculator', [
    'transport' => 'stdio',
    'command' => 'php',
    'args' => [__DIR__ . '/mcp-servers/calculator.php'],
]);

$manager->connect('calculator');
```

`connect()` runs the MCP handshake (`initialize` → `notifications/initialized`) and
discovers the server's tools and resources; every tool is indexed under a namespaced
**registry name** — `mcp_{server}_{tool}` — so a `McpClientManager` juggling several
servers never collides on a bare tool name:

```php
foreach ($manager->listAllTools() as $tool) {
    echo $tool->getRegistryName(), ' — ', $tool->description, "\n";
}
// mcp_calculator_add — Add two numbers

$result = $manager->callTool('mcp_calculator_add', ['a' => 2, 'b' => 3]);
// ['sum' => 5]

$manager->disconnectAll();
```

A remote server over HTTP/SSE looks the same, just with a different transport config:

```php
$manager->registerServer('remote-docs', [
    'transport' => 'http-sse',
    'url' => 'https://mcp.example.com/',
    'headers' => ['Authorization' => 'Bearer ' . $token],
    'timeout' => 30,
]);
```

`connectAll()` connects every registered server and returns a `[name => Throwable]` map
of the ones that failed, instead of throwing on the first bad server.

## What it is

- **`McpClientManager`** — the entry point most callers use. Registers servers from
  plain config arrays, owns their `McpConnection`s, and aggregates + routes tool calls
  across all of them by registry name.
- **`McpConnection`** — one server's live session: handshake, capability discovery,
  `listTools()` / `getTool()` / `callTool()`, `listResources()` / `readResource()`, and
  `refresh()` to re-discover after the server's tool list changes.
- **`TransportInterface`** — the seam a transport implements: `connect()`,
  `disconnect()`, `isConnected()`, `request()` (JSON-RPC call, blocks for a reply), and
  `notify()` (JSON-RPC notification, no reply expected).
  - **`StdioTransport`** — spawns the server as a subprocess (`proc_open`) and speaks
    newline-delimited JSON-RPC over its stdin/stdout.
  - **`HttpSseTransport`** — POSTs JSON-RPC over Guzzle and parses the reply whether the
    server answers with a plain JSON body or a `text/event-stream` SSE payload.
- **Typed contracts** — `McpTool`, `McpResource`, and `McpCapabilities` are immutable
  value objects built with `fromArray()` from the server's raw JSON-RPC responses, not
  arrays passed around by convention.
- **A layered exception hierarchy** — every exception extends `McpException`, which
  carries the offending `serverName` for context; `McpConnectionException`,
  `McpTransportException`, `McpToolException`, and `McpServerException` (which also
  carries the JSON-RPC `errorCode` and `errorData`) narrow the failure mode.

**Be honest about scope:** this package is a client only — it does not implement or
host an MCP server. It does not persist tool schemas, cache results, or retry failed
calls; those policies belong to the host application.

## What's inside

| Namespace | What it provides |
|-----------|------------------|
| `Milpa\McpClient` | `McpClientManager`, `McpConnection` |
| `Milpa\McpClient\Contracts` | `TransportInterface`, `McpTool`, `McpResource`, `McpCapabilities`, `McpException`, `McpConnectionException`, `McpServerException`, `McpToolException`, `McpTransportException` |
| `Milpa\McpClient\Transports` | `StdioTransport`, `HttpSseTransport` |

Every public symbol carries a DocBlock.

## Requirements

- PHP **≥ 8.3**
- [`guzzlehttp/guzzle`](https://packagist.org/packages/guzzlehttp/guzzle) **^7.10** (used by `HttpSseTransport`)

## Documentation

**Full API reference: [getmilpa.github.io/mcp-client](https://getmilpa.github.io/mcp-client/)** — generated
straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © TeamX Agency.

---

Milpa is designed, built, and maintained by **[TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=mcp-client)**.
