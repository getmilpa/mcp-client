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

namespace Milpa\McpClient;

use Milpa\McpClient\Contracts\McpConnectionException;
use Milpa\McpClient\Contracts\McpTool;
use Milpa\McpClient\Contracts\McpToolException;
use Throwable;
use Milpa\McpClient\Contracts\TransportInterface;
use Milpa\McpClient\Transports\HttpSseTransport;
use Milpa\McpClient\Transports\StdioTransport;

/**
 * Manager for multiple MCP server connections.
 *
 * This class handles:
 * - Creating connections from configuration
 * - Managing connection lifecycle
 * - Aggregating tools from all connected servers
 * - Routing tool calls to the correct server
 */
class McpClientManager
{
    /** @var array<string, McpConnection> Server name to connection mapping */
    private array $connections = [];

    /** @var array<string, McpConnection> Tool name to connection mapping */
    private array $toolIndex = [];

    /**
     * Register a connection from configuration.
     *
     * @param string               $name   Server name
     * @param array<string, mixed> $config Server configuration
     */
    public function registerServer(string $name, array $config): void
    {
        $transport = $this->createTransport($name, $config);
        $this->connections[$name] = new McpConnection($name, $transport);
    }

    /**
     * Connect to a specific server.
     *
     * @param string $name Server name
     *
     * @throws McpConnectionException If connection fails
     */
    public function connect(string $name): void
    {
        if (!isset($this->connections[$name])) {
            throw new McpConnectionException(
                "Server not registered: {$name}",
                $name
            );
        }

        $connection = $this->connections[$name];
        $connection->connect();

        // Index tools for fast lookup
        foreach ($connection->listTools() as $tool) {
            $registryName = $tool->getRegistryName();
            $this->toolIndex[$registryName] = $connection;
        }
    }

    /**
     * Connect to all registered servers.
     *
     * @return array<string, Throwable> Map of server names to connection errors
     */
    public function connectAll(): array
    {
        $errors = [];

        foreach ($this->connections as $name => $connection) {
            try {
                $this->connect($name);
            } catch (\Throwable $e) {
                $errors[$name] = $e;
            }
        }

        return $errors;
    }

    /**
     * Disconnect from a specific server.
     */
    public function disconnect(string $name): void
    {
        if (isset($this->connections[$name])) {
            $connection = $this->connections[$name];

            // Remove tools from index
            foreach ($connection->listTools() as $tool) {
                unset($this->toolIndex[$tool->getRegistryName()]);
            }

            $connection->disconnect();
        }
    }

    /**
     * Disconnect from all servers.
     */
    public function disconnectAll(): void
    {
        foreach (array_keys($this->connections) as $name) {
            $this->disconnect($name);
        }
    }

    /**
     * Get a specific connection.
     */
    public function getConnection(string $name): ?McpConnection
    {
        return $this->connections[$name] ?? null;
    }

    /**
     * Get all connections.
     *
     * @return array<string, McpConnection> Server name to connection mapping
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * List all tools from all connected servers.
     *
     * @return McpTool[]
     */
    public function listAllTools(): array
    {
        $tools = [];

        foreach ($this->connections as $connection) {
            if ($connection->isConnected()) {
                foreach ($connection->listTools() as $tool) {
                    $tools[] = $tool;
                }
            }
        }

        return $tools;
    }

    /**
     * Get a tool by its registry name.
     *
     * @param string $registryName Format: mcp_{server}_{tool}
     */
    public function getTool(string $registryName): ?McpTool
    {
        if (!isset($this->toolIndex[$registryName])) {
            return null;
        }

        $connection = $this->toolIndex[$registryName];

        // Extract tool name from registry name (mcp_server_toolname)
        $parts = explode('_', $registryName, 3);
        if (count($parts) < 3) {
            return null;
        }

        return $connection->getTool($parts[2]);
    }

    /**
     * Call a tool by its registry name.
     *
     * @param string               $registryName Format: mcp_{server}_{tool}
     * @param array<string, mixed> $arguments    Tool arguments
     *
     * @return array<string, mixed> Tool result
     *
     * @throws McpToolException If tool not found or execution fails
     */
    public function callTool(string $registryName, array $arguments = []): array
    {
        if (!isset($this->toolIndex[$registryName])) {
            throw new McpToolException("Tool not found: {$registryName}");
        }

        $connection = $this->toolIndex[$registryName];

        // Extract tool name from registry name
        $parts = explode('_', $registryName, 3);
        if (count($parts) < 3) {
            throw new McpToolException("Invalid tool registry name: {$registryName}");
        }

        $toolName = $parts[2];

        return $connection->callTool($toolName, $arguments);
    }

    /**
     * Check if a tool exists.
     */
    public function hasTool(string $registryName): bool
    {
        return isset($this->toolIndex[$registryName]);
    }

    /**
     * Get the connection that provides a tool.
     */
    public function getToolConnection(string $registryName): ?McpConnection
    {
        return $this->toolIndex[$registryName] ?? null;
    }

    /**
     * Create transport from configuration.
     *
     * @param string               $name   Server name
     * @param array<string, mixed> $config Server configuration
     *
     * @return TransportInterface
     *
     * @throws McpConnectionException If transport type is unknown
     */
    private function createTransport(string $name, array $config): TransportInterface
    {
        $type = $config['transport'] ?? 'stdio';

        return match ($type) {
            'http', 'sse', 'http-sse' => new HttpSseTransport(
                baseUrl: $config['url'] ?? throw new McpConnectionException(
                    "Missing 'url' for HTTP transport",
                    $name
                ),
                headers: $config['headers'] ?? [],
                timeout: $config['timeout'] ?? 30,
                serverName: $name,
            ),

            'stdio' => new StdioTransport(
                command: $config['command'] ?? throw new McpConnectionException(
                    "Missing 'command' for Stdio transport",
                    $name
                ),
                args: $config['args'] ?? [],
                env: $config['env'] ?? [],
                workingDir: $config['cwd'] ?? null,
                timeout: $config['timeout'] ?? 30,
                serverName: $name,
            ),

            default => throw new McpConnectionException(
                "Unknown transport type: {$type}",
                $name
            ),
        };
    }

    /**
     * Destructor ensures all connections are closed.
     */
    public function __destruct()
    {
        $this->disconnectAll();
    }
}
