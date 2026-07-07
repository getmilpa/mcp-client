<?php

/**
 * This file is part of Milpa MCP Client — the Model Context Protocol client for the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/mcp-client
 */

declare(strict_types=1);

namespace Milpa\McpClient\Contracts;

/**
 * Interface for MCP transport implementations.
 *
 * Transports handle the low-level communication with MCP servers.
 * Supported transports:
 * - HTTP/SSE: For remote MCP servers (like Cloudflare)
 * - Stdio: For local MCP servers spawned as subprocesses (like Namecheap)
 */
interface TransportInterface
{
    /**
     * Connect to the MCP server.
     *
     * @throws McpConnectionException If connection fails
     */
    public function connect(): void;

    /**
     * Disconnect from the MCP server.
     */
    public function disconnect(): void;

    /**
     * Check if transport is connected.
     */
    public function isConnected(): bool;

    /**
     * Send a JSON-RPC request and wait for response.
     *
     * @param string               $method JSON-RPC method name
     * @param array<string, mixed> $params Method parameters
     *
     * @return array<string, mixed> Response data
     *
     * @throws McpTransportException If communication fails
     */
    public function request(string $method, array $params = []): array;

    /**
     * Send a JSON-RPC notification (no response expected).
     *
     * @param string               $method JSON-RPC method name
     * @param array<string, mixed> $params Method parameters
     */
    public function notify(string $method, array $params = []): void;
}
