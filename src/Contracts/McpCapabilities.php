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
 * Represents server capabilities.
 */
readonly class McpCapabilities
{
    public function __construct(
        public bool $supportsTools = false,
        public bool $supportsResources = false,
        public bool $supportsPrompts = false,
        public bool $supportsLogging = false,
        public ?string $protocolVersion = null,
    ) {
    }

    /**
     * Create from an MCP `initialize` response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $capabilities = $data['capabilities'] ?? [];

        return new self(
            supportsTools: isset($capabilities['tools']),
            supportsResources: isset($capabilities['resources']),
            supportsPrompts: isset($capabilities['prompts']),
            supportsLogging: isset($capabilities['logging']),
            protocolVersion: $data['protocolVersion'] ?? null,
        );
    }
}
