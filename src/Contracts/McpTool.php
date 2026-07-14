<?php

/**
 * This file is part of Milpa MCP Client — the Model Context Protocol client for the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/mcp-client
 */

declare(strict_types=1);

namespace Milpa\McpClient\Contracts;

/**
 * Represents a tool exposed by an MCP server.
 */
readonly class McpTool
{
    /**
     * @param array<string, mixed> $inputSchema
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public string $serverName,
    ) {
    }

    /**
     * Get the namespaced tool name for registration.
     *
     * Format: mcp_{server}_{tool}
     */
    public function getRegistryName(): string
    {
        return "mcp_{$this->serverName}_{$this->name}";
    }

    /**
     * Create from MCP server response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $serverName): self
    {
        return new self(
            name: $data['name'],
            description: $data['description'] ?? '',
            inputSchema: $data['inputSchema'] ?? ['type' => 'object', 'properties' => []],
            serverName: $serverName,
        );
    }
}
