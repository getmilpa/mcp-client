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

use Exception;
use Milpa\McpClient\Contracts\McpCapabilities;
use Milpa\McpClient\Contracts\McpResource;
use Milpa\McpClient\Contracts\McpTool;
use Milpa\McpClient\Contracts\McpToolException;
use Milpa\McpClient\Contracts\TransportInterface;

/**
 * Represents an active connection to an MCP server.
 *
 * This class wraps a transport and provides high-level methods
 * for interacting with the MCP server (tools, resources, etc.).
 */
class McpConnection
{
    private ?McpCapabilities $capabilities = null;

    /** @var McpTool[] */
    private array $tools = [];

    /** @var McpResource[] */
    private array $resources = [];

    private bool $initialized = false;

    public function __construct(
        private readonly string $name,
        private readonly TransportInterface $transport,
    ) {
    }

    /**
     * Get connection name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Connect and initialize the MCP server.
     */
    public function connect(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->transport->connect();
        $this->initialized = true;

        // Discover server capabilities
        $this->discoverCapabilities();

        // Discover available tools
        $this->discoverTools();

        // Discover available resources
        $this->discoverResources();
    }

    /**
     * Discover server capabilities.
     */
    private function discoverCapabilities(): void
    {
        try {
            $response = $this->transport->request('server/capabilities', []);
            $this->capabilities = McpCapabilities::fromArray($response);
        } catch (Exception $e) {
            // Server may not support capabilities query, use defaults
            $this->capabilities = new McpCapabilities();
        }
    }

    /**
     * Disconnect from the MCP server.
     */
    public function disconnect(): void
    {
        $this->transport->disconnect();
        $this->initialized = false;
        $this->tools = [];
        $this->resources = [];
    }

    /**
     * Check if connection is active.
     */
    public function isConnected(): bool
    {
        return $this->initialized && $this->transport->isConnected();
    }

    /**
     * Get server capabilities.
     */
    public function getCapabilities(): ?McpCapabilities
    {
        return $this->capabilities;
    }

    /**
     * List available tools from this server.
     *
     * @return McpTool[]
     */
    public function listTools(): array
    {
        return $this->tools;
    }

    /**
     * Get a specific tool by name.
     */
    public function getTool(string $name): ?McpTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name === $name) {
                return $tool;
            }
        }
        return null;
    }

    /**
     * Call a tool on this MCP server.
     *
     * @param string               $toolName  Name of the tool to call
     * @param array<string, mixed> $arguments Tool arguments
     *
     * @return array<string, mixed> Tool result
     *
     * @throws McpToolException If tool execution fails
     */
    public function callTool(string $toolName, array $arguments = []): array
    {
        $tool = $this->getTool($toolName);

        if (!$tool) {
            throw new McpToolException(
                "Tool not found: {$toolName}",
                $this->name
            );
        }

        try {
            $result = $this->transport->request('tools/call', [
                'name' => $toolName,
                'arguments' => (object) $arguments,
            ]);

            return $result;

        } catch (Exception $e) {
            throw new McpToolException(
                "Failed to call tool {$toolName}: " . $e->getMessage(),
                $this->name,
                0,
                $e
            );
        }
    }

    /**
     * List available resources from this server.
     *
     * @return McpResource[]
     */
    public function listResources(): array
    {
        return $this->resources;
    }

    /**
     * Read a resource by URI.
     *
     * @param string $uri Resource URI
     *
     * @return array<string, mixed> Resource content
     */
    public function readResource(string $uri): array
    {
        return $this->transport->request('resources/read', [
            'uri' => $uri,
        ]);
    }

    /**
     * Discover tools from the MCP server.
     */
    private function discoverTools(): void
    {
        try {
            $response = $this->transport->request('tools/list', []);
            $toolsData = $response['tools'] ?? [];

            $this->tools = [];
            foreach ($toolsData as $toolData) {
                $this->tools[] = McpTool::fromArray($toolData, $this->name);
            }

        } catch (Exception $e) {
            // Server may not support tools
            $this->tools = [];
        }
    }

    /**
     * Discover resources from the MCP server.
     */
    private function discoverResources(): void
    {
        try {
            $response = $this->transport->request('resources/list', []);
            $resourcesData = $response['resources'] ?? [];

            $this->resources = [];
            foreach ($resourcesData as $resourceData) {
                $this->resources[] = McpResource::fromArray($resourceData, $this->name);
            }

        } catch (Exception $e) {
            // Server may not support resources
            $this->resources = [];
        }
    }

    /**
     * Refresh tool and resource lists from server.
     */
    public function refresh(): void
    {
        $this->discoverTools();
        $this->discoverResources();
    }

    /**
     * Get raw transport for advanced operations.
     */
    public function getTransport(): TransportInterface
    {
        return $this->transport;
    }
}
