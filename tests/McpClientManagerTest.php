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
use Milpa\McpClient\McpClientManager;
use Milpa\McpClient\McpConnection;
use Milpa\McpClient\Contracts\McpConnectionException;
use Milpa\McpClient\Contracts\McpToolException;
use Milpa\McpClient\Contracts\McpTool;

class McpClientManagerTest extends TestCase
{
    private McpClientManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new McpClientManager();
    }

    public function testRegisterServerCreatesConnection(): void
    {
        $this->manager->registerServer('test_server', [
            'transport' => 'stdio',
            'command' => 'echo',
            'args' => ['hello'],
        ]);

        $connection = $this->manager->getConnection('test_server');
        $this->assertInstanceOf(McpConnection::class, $connection);
    }

    public function testRegisterServerWithHttpTransport(): void
    {
        $this->manager->registerServer('http_server', [
            'transport' => 'http-sse',
            'url' => 'https://example.com/mcp',
            'headers' => ['Authorization' => 'Bearer test'],
        ]);

        $connection = $this->manager->getConnection('http_server');
        $this->assertInstanceOf(McpConnection::class, $connection);
    }

    public function testRegisterServerWithMissingUrlThrows(): void
    {
        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage("Missing 'url'");

        $this->manager->registerServer('bad_http', [
            'transport' => 'http-sse',
        ]);
    }

    public function testRegisterServerWithMissingCommandThrows(): void
    {
        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage("Missing 'command'");

        $this->manager->registerServer('bad_stdio', [
            'transport' => 'stdio',
        ]);
    }

    public function testRegisterServerWithUnknownTransportThrows(): void
    {
        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('Unknown transport type');

        $this->manager->registerServer('bad_transport', [
            'transport' => 'unknown',
        ]);
    }

    public function testGetConnectionReturnsNullForUnregistered(): void
    {
        $connection = $this->manager->getConnection('nonexistent');
        $this->assertNull($connection);
    }

    public function testGetConnections(): void
    {
        $this->manager->registerServer('server1', [
            'transport' => 'stdio',
            'command' => 'cmd1',
        ]);
        $this->manager->registerServer('server2', [
            'transport' => 'http-sse',
            'url' => 'https://example.com',
        ]);

        $connections = $this->manager->getConnections();

        $this->assertCount(2, $connections);
        $this->assertArrayHasKey('server1', $connections);
        $this->assertArrayHasKey('server2', $connections);
    }

    public function testConnectThrowsForUnregisteredServer(): void
    {
        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('Server not registered');

        $this->manager->connect('unregistered');
    }

    public function testListAllToolsReturnsEmptyWithNoConnections(): void
    {
        $tools = $this->manager->listAllTools();
        $this->assertEmpty($tools);
    }

    public function testHasToolReturnsFalseForMissingTool(): void
    {
        $this->assertFalse($this->manager->hasTool('mcp_server_tool'));
    }

    public function testCallToolThrowsForMissingTool(): void
    {
        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('Tool not found');

        $this->manager->callTool('mcp_nonexistent_tool', []);
    }

    public function testCallToolThrowsForInvalidRegistryName(): void
    {
        // This test requires we somehow have a tool indexed without proper naming
        // Since that's not possible in normal operation, we test the exception message
        $this->expectException(McpToolException::class);

        $this->manager->callTool('invalid_name', []);
    }

    public function testGetToolReturnsNullForMissingTool(): void
    {
        $tool = $this->manager->getTool('mcp_server_nonexistent');
        $this->assertNull($tool);
    }

    public function testGetToolConnectionReturnsNullForMissingTool(): void
    {
        $connection = $this->manager->getToolConnection('mcp_server_tool');
        $this->assertNull($connection);
    }

    public function testDisconnectNonExistentServerDoesNotThrow(): void
    {
        // Should not throw, just do nothing
        $this->manager->disconnect('nonexistent');
        $this->assertTrue(true); // Assertion that we got here
    }

    public function testDisconnectAll(): void
    {
        $this->manager->registerServer('server1', [
            'transport' => 'stdio',
            'command' => 'cmd1',
        ]);
        $this->manager->registerServer('server2', [
            'transport' => 'stdio',
            'command' => 'cmd2',
        ]);

        // Should not throw
        $this->manager->disconnectAll();

        // Connections should still exist but be disconnected
        $this->assertCount(2, $this->manager->getConnections());
    }

    public function testDefaultTransportIsStdio(): void
    {
        // When no transport is specified, it defaults to 'stdio'
        $this->manager->registerServer('default_transport', [
            'command' => 'some_command',
        ]);

        $connection = $this->manager->getConnection('default_transport');
        $this->assertInstanceOf(McpConnection::class, $connection);
    }

    public function testHttpTransportVariants(): void
    {
        // Test 'http' variant
        $this->manager->registerServer('http_variant', [
            'transport' => 'http',
            'url' => 'https://example.com/mcp',
        ]);
        $this->assertNotNull($this->manager->getConnection('http_variant'));

        // Test 'sse' variant
        $this->manager->registerServer('sse_variant', [
            'transport' => 'sse',
            'url' => 'https://example.com/mcp',
        ]);
        $this->assertNotNull($this->manager->getConnection('sse_variant'));
    }

    public function testStdioTransportWithAllOptions(): void
    {
        $this->manager->registerServer('full_stdio', [
            'transport' => 'stdio',
            'command' => 'npx',
            'args' => ['-y', '@some/package'],
            'env' => ['API_KEY' => 'secret'],
            'cwd' => '/tmp',
            'timeout' => 60,
        ]);

        $connection = $this->manager->getConnection('full_stdio');
        $this->assertInstanceOf(McpConnection::class, $connection);
    }

    public function testHttpTransportWithTimeout(): void
    {
        $this->manager->registerServer('http_timeout', [
            'transport' => 'http-sse',
            'url' => 'https://example.com/mcp',
            'timeout' => 120,
            'headers' => ['X-Custom' => 'value'],
        ]);

        $connection = $this->manager->getConnection('http_timeout');
        $this->assertInstanceOf(McpConnection::class, $connection);
    }

    public function testConnectAllReturnsErrorsMap(): void
    {
        // Register servers that will fail to connect (no actual server running)
        $this->manager->registerServer('will_fail', [
            'transport' => 'stdio',
            'command' => 'nonexistent_command_that_should_fail',
        ]);

        $errors = $this->manager->connectAll();

        // Should have an error for the failing server
        $this->assertArrayHasKey('will_fail', $errors);
        $this->assertInstanceOf(\Throwable::class, $errors['will_fail']);
    }

    // ========== Additional Tests for Coverage ==========

    public function testListAllToolsWithConnectedServer(): void
    {
        // Create a mock connection
        $mockConnection = $this->createMock(McpConnection::class);
        $mockConnection->method('isConnected')->willReturn(true);
        $mockConnection->method('listTools')->willReturn([
            new McpTool('test_tool', 'A test tool', [], 'server1'),
        ]);

        // Inject the mock connection via reflection
        $this->injectConnection('server1', $mockConnection);

        $tools = $this->manager->listAllTools();

        $this->assertCount(1, $tools);
        $this->assertEquals('test_tool', $tools[0]->name);
    }

    public function testListAllToolsSkipsDisconnectedServers(): void
    {
        // Create mock connections
        $connectedMock = $this->createMock(McpConnection::class);
        $connectedMock->method('isConnected')->willReturn(true);
        $connectedMock->method('listTools')->willReturn([
            new McpTool('tool1', 'Tool 1', [], 'connected'),
        ]);

        $disconnectedMock = $this->createMock(McpConnection::class);
        $disconnectedMock->method('isConnected')->willReturn(false);
        $disconnectedMock->expects($this->never())->method('listTools');

        $this->injectConnection('connected', $connectedMock);
        $this->injectConnection('disconnected', $disconnectedMock);

        $tools = $this->manager->listAllTools();

        $this->assertCount(1, $tools);
    }

    public function testGetToolWithValidRegistryName(): void
    {
        $tool = new McpTool('my_tool', 'My tool', [], 'server1');

        $mockConnection = $this->createMock(McpConnection::class);
        $mockConnection->method('getTool')
            ->with('my_tool')
            ->willReturn($tool);

        // Inject into toolIndex via reflection
        $this->injectToolIndex('mcp_server1_my_tool', $mockConnection);

        $result = $this->manager->getTool('mcp_server1_my_tool');

        $this->assertNotNull($result);
        $this->assertEquals('my_tool', $result->name);
    }

    public function testGetToolWithInvalidRegistryNameFormat(): void
    {
        $mockConnection = $this->createMock(McpConnection::class);

        // Inject with invalid format (less than 3 parts)
        $this->injectToolIndex('invalid_name', $mockConnection);

        $result = $this->manager->getTool('invalid_name');

        $this->assertNull($result);
    }

    public function testCallToolSuccess(): void
    {
        $mockConnection = $this->createMock(McpConnection::class);
        $mockConnection->method('callTool')
            ->with('my_tool', ['arg' => 'value'])
            ->willReturn(['result' => 'success']);

        $this->injectToolIndex('mcp_server1_my_tool', $mockConnection);

        $result = $this->manager->callTool('mcp_server1_my_tool', ['arg' => 'value']);

        $this->assertEquals(['result' => 'success'], $result);
    }

    public function testCallToolWithInvalidRegistryNameThrows(): void
    {
        $mockConnection = $this->createMock(McpConnection::class);
        $this->injectToolIndex('bad', $mockConnection);

        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('Invalid tool registry name');

        $this->manager->callTool('bad', []);
    }

    public function testDisconnectRemovesToolsFromIndex(): void
    {
        $tool = new McpTool('tool1', 'Tool 1', [], 'server1');

        $mockConnection = $this->createMock(McpConnection::class);
        $mockConnection->method('listTools')->willReturn([$tool]);
        $mockConnection->expects($this->once())->method('disconnect');

        $this->injectConnection('server1', $mockConnection);
        $this->injectToolIndex($tool->getRegistryName(), $mockConnection);

        // Verify tool exists before disconnect
        $this->assertTrue($this->manager->hasTool($tool->getRegistryName()));

        $this->manager->disconnect('server1');

        // Tool should be removed from index
        $this->assertFalse($this->manager->hasTool($tool->getRegistryName()));
    }

    public function testGetToolConnectionReturnsCorrectConnection(): void
    {
        $mockConnection = $this->createMock(McpConnection::class);
        $this->injectToolIndex('mcp_server1_tool', $mockConnection);

        $result = $this->manager->getToolConnection('mcp_server1_tool');

        $this->assertSame($mockConnection, $result);
    }

    public function testConnectIndexesToolsFromServer(): void
    {
        $tool = new McpTool('remote_tool', 'Remote tool', [], 'test');

        $mockConnection = $this->createMock(McpConnection::class);
        $mockConnection->expects($this->once())->method('connect');
        $mockConnection->method('listTools')->willReturn([$tool]);

        $this->injectConnection('test', $mockConnection);

        $this->manager->connect('test');

        $this->assertTrue($this->manager->hasTool('mcp_test_remote_tool'));
    }

    // ========== Helper Methods ==========

    private function injectConnection(string $name, McpConnection $connection): void
    {
        $reflection = new \ReflectionClass($this->manager);
        $prop = $reflection->getProperty('connections');
        $prop->setAccessible(true);
        $connections = $prop->getValue($this->manager);
        $connections[$name] = $connection;
        $prop->setValue($this->manager, $connections);
    }

    private function injectToolIndex(string $registryName, McpConnection $connection): void
    {
        $reflection = new \ReflectionClass($this->manager);
        $prop = $reflection->getProperty('toolIndex');
        $prop->setAccessible(true);
        $index = $prop->getValue($this->manager);
        $index[$registryName] = $connection;
        $prop->setValue($this->manager, $index);
    }
}
