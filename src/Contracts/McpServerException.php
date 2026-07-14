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
 * Exception thrown when MCP server returns an error response.
 */
class McpServerException extends McpException
{
    /**
     * @param string                    $message    Error message
     * @param int                       $errorCode  MCP error code
     * @param array<string, mixed>|null $errorData  Additional error data from the server
     * @param string|null               $serverName Name of the MCP server
     */
    public function __construct(
        string $message,
        public readonly int $errorCode,
        public readonly ?array $errorData = null,
        ?string $serverName = null
    ) {
        parent::__construct($message, $serverName, $errorCode);
    }
}
