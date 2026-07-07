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
 * Base exception for MCP Client errors.
 *
 * All MCP-related exceptions extend this class, allowing for
 * easy catch-all error handling while also enabling granular
 * exception handling when needed.
 */
class McpException extends \Exception
{
    /**
     * @param string          $message    Exception message
     * @param string|null     $serverName Name of the MCP server (for context in error messages)
     * @param int             $code       Exception code
     * @param \Throwable|null $previous   Previous exception for chaining
     */
    public function __construct(
        string $message,
        public readonly ?string $serverName = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $prefix = $serverName ? "[MCP:{$serverName}] " : "[MCP] ";
        parent::__construct($prefix . $message, $code, $previous);
    }

    /**
     * Get the server name associated with this exception.
     */
    public function getServerName(): ?string
    {
        return $this->serverName;
    }
}
