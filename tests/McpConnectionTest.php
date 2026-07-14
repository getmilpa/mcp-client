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

namespace Milpa\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use Milpa\McpClient\McpConnection;
use Milpa\McpClient\Contracts\TransportInterface;
use Milpa\McpClient\Contracts\McpToolException;

class McpConnectionTest extends TestCase
{
    private TransportInterface $transport;
    private McpConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = $this->createMock(TransportInterface::class);
        $this->connection = new McpConnection('test_server', $this->transport);
    }

    public function testGetName(): void
    {
        $this->assertEquals('test_server', $this->connection->getName());
    }

    public function testIsConnectedReturnsFalseInitially(): void
    {
        $this->transport->method('isConnected')->willReturn(false);
        $this->assertFalse($this->connection->isConnected());
    }

    public function testConnectCallsTransportConnect(): void
    {
        $this->transport->expects($this->once())
            ->method('connect');

        // Mock empty responses for discovery calls
        $this->transport->method('request')
            ->willReturn(['tools' => [], 'resources' => []]);

        $this->connection->connect();
    }

    public function testConnectDoesNotReconnectIfAlreadyConnected(): void
    {
        $this->transport->expects($this->once())
            ->method('connect');

        $this->transport->method('request')
            ->willReturn(['tools' => [], 'resources' => []]);

        $this->connection->connect();
        $this->connection->connect(); // Second call should be ignored
    }

    public function testDisconnectCallsTransportDisconnect(): void
    {
        $this->transport->expects($this->once())
            ->method('disconnect');

        $this->connection->disconnect();
    }

    public function testDisconnectClearsToolsAndResources(): void
    {
        // First connect and discover tools
        $this->transport->method('request')
            ->willReturnCallback(function ($method) {
                if ($method === 'tools/list') {
                    return ['tools' => [
                        ['name' => 'tool1', 'description' => 'Tool 1', 'inputSchema' => []],
                    ]];
                }
                return ['resources' => []];
            });

        $this->connection->connect();
        $this->assertCount(1, $this->connection->listTools());

        $this->connection->disconnect();
        $this->assertEmpty($this->connection->listTools());
    }

    public function testListToolsReturnsEmptyBeforeConnect(): void
    {
        $this->assertEmpty($this->connection->listTools());
    }

    public function testListToolsAfterConnect(): void
    {
        $this->transport->method('request')
            ->willReturnCallback(function ($method) {
                if ($method === 'tools/list') {
                    return ['tools' => [
                        ['name' => 'tool1', 'description' => 'First tool', 'inputSchema' => []],
                        ['name' => 'tool2', 'description' => 'Second tool', 'inputSchema' => []],
                    ]];
                }
                return ['resources' => []];
            });

        $this->connection->connect();

        $tools = $this->connection->listTools();
        $this->assertCount(2, $tools);
        $this->assertEquals('tool1', $tools[0]->name);
        $this->assertEquals('tool2', $tools[1]->name);
    }

    public function testGetToolReturnsToolByName(): void
    {
        $this->transport->method('request')
            ->willReturnCallback(function ($method) {
                if ($method === 'tools/list') {
                    return ['tools' => [
                        ['name' => 'find_tool', 'description' => 'Findable tool', 'inputSchema' => []],
                    ]];
                }
                return ['resources' => []];
            });

        $this->connection->connect();

        $tool = $this->connection->getTool('find_tool');
        $this->assertNotNull($tool);
        $this->assertEquals('find_tool', $tool->name);
    }

    public function testGetToolReturnsNullForMissingTool(): void
    {
        $this->transport->method('request')
            ->willReturn(['tools' => [], 'resources' => []]);

        $this->connection->connect();

        $tool = $this->connection->getTool('nonexistent');
        $this->assertNull($tool);
    }

    public function testCallToolSendsRequest(): void
    {
        $this->transport->method('request')
            ->willReturnCallback(function ($method, $params) {
                if ($method === 'tools/list') {
                    return ['tools' => [
                        ['name' => 'echo_tool', 'description' => 'Echoes', 'inputSchema' => []],
                    ]];
                }
                if ($method === 'tools/call') {
                    return [
                        'content' => [['type' => 'text', 'text' => 'Echo: ' . $params['arguments']->message]],
                    ];
                }
                return ['resources' => []];
            });

        $this->connection->connect();

        $result = $this->connection->callTool('echo_tool', ['message' => 'Hello']);
        $this->assertArrayHasKey('content', $result);
    }

    public function testCallToolThrowsForMissingTool(): void
    {
        $this->transport->method('request')
            ->willReturn(['tools' => [], 'resources' => []]);

        $this->connection->connect();

        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('Tool not found');

        $this->connection->callTool('missing_tool', []);
    }

    public function testCallToolWrapsTransportErrors(): void
    {
        $this->transport->method('request')
            ->willReturnCallback(function ($method) {
                if ($method === 'tools/list') {
                    return ['tools' => [
                        ['name' => 'failing_tool', 'description' => 'Fails', 'inputSchema' => []],
                    ]];
                }
                if ($method === 'tools/call') {
                    throw new \RuntimeException('Transport failed');
                }
                return ['resources' => []];
            });

        $this->connection->connect();

        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('Failed to call tool');

        $this->connection->callTool('failing_tool', []);
    }

    public function testListResourcesReturnsEmptyBeforeConnect(): void
    {
        $this->assertEmpty($this->connection->listResources());
    }

    public function testListResourcesAfterConnect(): void
    {
        $this->transport->method('request')
            ->willReturnCallback(function ($method) {
                if ($method === 'resources/list') {
                    return ['resources' => [
                        ['uri' => 'file://test.txt', 'name' => 'test.txt'],
                        ['uri' => 'file://data.json', 'name' => 'data.json'],
                    ]];
                }
                return ['tools' => []];
            });

        $this->connection->connect();

        $resources = $this->connection->listResources();
        $this->assertCount(2, $resources);
    }

    public function testReadResourceSendsRequest(): void
    {
        $this->transport->method('request')
            ->willReturnCallback(function ($method, $params) {
                if ($method === 'resources/read') {
                    return [
                        'contents' => [['type' => 'text', 'text' => 'File content']],
                    ];
                }
                return ['tools' => [], 'resources' => []];
            });

        $this->connection->connect();

        $result = $this->connection->readResource('file://test.txt');
        $this->assertArrayHasKey('contents', $result);
    }

    public function testGetCapabilitiesReturnsNullBeforeConnect(): void
    {
        $this->assertNull($this->connection->getCapabilities());
    }

    public function testRefreshReloadsToolsAndResources(): void
    {
        $callCount = 0;
        $this->transport->method('request')
            ->willReturnCallback(function ($method) use (&$callCount) {
                if ($method === 'tools/list') {
                    $callCount++;
                    return ['tools' => [
                        ['name' => 'tool_' . $callCount, 'description' => 'Tool', 'inputSchema' => []],
                    ]];
                }
                return ['resources' => []];
            });

        $this->connection->connect();
        $this->assertEquals('tool_1', $this->connection->listTools()[0]->name);

        $this->connection->refresh();
        // After refresh, tools/list is called again
        $this->assertEquals('tool_2', $this->connection->listTools()[0]->name);
    }

    public function testGetTransportReturnsTransport(): void
    {
        $transport = $this->connection->getTransport();
        $this->assertSame($this->transport, $transport);
    }

    public function testToolsDiscoveryHandlesErrors(): void
    {
        $this->transport->method('request')
            ->willThrowException(new \RuntimeException('Discovery failed'));

        $this->connection->connect();

        // Should have empty tools after failed discovery
        $this->assertEmpty($this->connection->listTools());
    }

    public function testResourcesDiscoveryHandlesErrors(): void
    {
        $this->transport->method('request')
            ->willThrowException(new \RuntimeException('Discovery failed'));

        $this->connection->connect();

        // Should have empty resources after failed discovery
        $this->assertEmpty($this->connection->listResources());
    }
}
