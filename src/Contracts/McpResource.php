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
 * Represents a resource exposed by an MCP server.
 */
readonly class McpResource
{
    public function __construct(
        public string $uri,
        public string $name,
        public ?string $description,
        public ?string $mimeType,
        public string $serverName,
    ) {
    }

    /**
     * Create from an MCP `resources/list` entry.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $serverName): self
    {
        return new self(
            uri: $data['uri'],
            name: $data['name'],
            description: $data['description'] ?? null,
            mimeType: $data['mimeType'] ?? null,
            serverName: $serverName,
        );
    }
}
