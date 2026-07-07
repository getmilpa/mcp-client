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

namespace Milpa\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use Milpa\McpClient\Contracts\McpException;
use Milpa\McpClient\Contracts\McpConnectionException;
use Milpa\McpClient\Contracts\McpTransportException;
use Milpa\McpClient\Contracts\McpServerException;
use Milpa\McpClient\Contracts\McpToolException;

class McpExceptionTest extends TestCase
{
    public function testMcpExceptionWithServerName(): void
    {
        $exception = new McpException('Test error', 'cloudflare');

        $this->assertStringContainsString('[MCP:cloudflare]', $exception->getMessage());
        $this->assertStringContainsString('Test error', $exception->getMessage());
        $this->assertEquals('cloudflare', $exception->getServerName());
    }

    public function testMcpExceptionWithoutServerName(): void
    {
        $exception = new McpException('Generic error');

        $this->assertStringContainsString('[MCP]', $exception->getMessage());
        $this->assertStringContainsString('Generic error', $exception->getMessage());
        $this->assertNull($exception->getServerName());
    }

    public function testMcpExceptionWithCode(): void
    {
        $exception = new McpException('Error with code', 'server', 500);

        $this->assertEquals(500, $exception->getCode());
    }

    public function testMcpExceptionWithPreviousException(): void
    {
        $previous = new \RuntimeException('Original error');
        $exception = new McpException('Wrapped error', 'server', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testMcpConnectionExceptionInheritance(): void
    {
        $exception = new McpConnectionException('Connection failed', 'remote_server');

        $this->assertInstanceOf(McpException::class, $exception);
        $this->assertEquals('remote_server', $exception->getServerName());
        $this->assertStringContainsString('Connection failed', $exception->getMessage());
    }

    public function testMcpTransportExceptionInheritance(): void
    {
        $exception = new McpTransportException('Transport error', 'local_server');

        $this->assertInstanceOf(McpException::class, $exception);
        $this->assertEquals('local_server', $exception->getServerName());
    }

    public function testMcpToolExceptionInheritance(): void
    {
        $exception = new McpToolException('Tool failed', 'tool_server');

        $this->assertInstanceOf(McpException::class, $exception);
        $this->assertEquals('tool_server', $exception->getServerName());
    }

    public function testMcpServerExceptionWithErrorCode(): void
    {
        $exception = new McpServerException(
            message: 'Server returned error',
            errorCode: -32600,
            errorData: ['detail' => 'Invalid request'],
            serverName: 'api_server'
        );

        $this->assertInstanceOf(McpException::class, $exception);
        $this->assertEquals(-32600, $exception->errorCode);
        $this->assertEquals(['detail' => 'Invalid request'], $exception->errorData);
        $this->assertEquals('api_server', $exception->getServerName());
        // The error code should also be set as the exception code
        $this->assertEquals(-32600, $exception->getCode());
    }

    public function testMcpServerExceptionWithNullErrorData(): void
    {
        $exception = new McpServerException(
            message: 'Simple error',
            errorCode: -32601,
            errorData: null,
            serverName: 'server'
        );

        $this->assertNull($exception->errorData);
    }

    public function testMcpServerExceptionWithNullServerName(): void
    {
        $exception = new McpServerException(
            message: 'Error without server',
            errorCode: -32602,
            errorData: null,
            serverName: null
        );

        $this->assertNull($exception->getServerName());
        $this->assertStringContainsString('[MCP]', $exception->getMessage());
    }

    public function testExceptionChaining(): void
    {
        $transport = new McpTransportException('Low level error', 'server1');
        $connection = new McpConnectionException('Connection wrapper', 'server1', 0, $transport);

        $this->assertSame($transport, $connection->getPrevious());
    }

    public function testAllExceptionsCanBeCaughtAsMcpException(): void
    {
        $exceptions = [
            new McpConnectionException('Connection', 'server'),
            new McpTransportException('Transport', 'server'),
            new McpToolException('Tool', 'server'),
            new McpServerException('Server', -32000, null, 'server'),
        ];

        foreach ($exceptions as $exception) {
            $caught = false;
            try {
                throw $exception;
            } catch (McpException $e) {
                $caught = true;
            }
            $this->assertTrue($caught, get_class($exception) . ' should be catchable as McpException');
        }
    }
}
