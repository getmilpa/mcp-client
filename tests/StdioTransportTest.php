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
use Milpa\McpClient\Contracts\McpConnectionException;
use Milpa\McpClient\Contracts\McpServerException;
use Milpa\McpClient\Contracts\McpTransportException;
use Milpa\McpClient\Transports\StdioTransport;

class StdioTransportTest extends TestCase
{
    private string $mockServerScript;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a temporary mock MCP server script
        $this->mockServerScript = sys_get_temp_dir() . '/mock_mcp_server_' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->mockServerScript)) {
            unlink($this->mockServerScript);
        }
    }

    private function createMockServer(string $behavior): void
    {
        $script = '<?php ' . $behavior;
        file_put_contents($this->mockServerScript, $script);
    }

    public function testConstructorSetsProperties(): void
    {
        $transport = new StdioTransport(
            command: '/usr/bin/node',
            args: ['server.js', '--port', '8080'],
            env: ['NODE_ENV' => 'production'],
            workingDir: '/tmp',
            timeout: 60,
            serverName: 'test-server'
        );

        $this->assertFalse($transport->isConnected());
    }

    public function testIsConnectedReturnsFalseInitially(): void
    {
        $transport = new StdioTransport(command: 'echo');
        $this->assertFalse($transport->isConnected());
    }

    public function testBuildCommandWithArgs(): void
    {
        $transport = new StdioTransport(
            command: 'node',
            args: ['script.js', '--option', 'value with spaces']
        );

        // Use reflection to test private buildCommand method
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('buildCommand');
        $method->setAccessible(true);

        $command = $method->invoke($transport);

        $this->assertStringContainsString('node', $command);
        $this->assertStringContainsString("'script.js'", $command);
        $this->assertStringContainsString("'--option'", $command);
        $this->assertStringContainsString("'value with spaces'", $command);
    }

    public function testBuildCommandWithoutArgs(): void
    {
        $transport = new StdioTransport(command: 'simple-command');

        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('buildCommand');
        $method->setAccessible(true);

        $command = $method->invoke($transport);

        $this->assertEquals('simple-command', $command);
    }

    public function testConnectFailsWithInvalidCommand(): void
    {
        $transport = new StdioTransport(
            command: '/nonexistent/command/that/does/not/exist',
            serverName: 'invalid-server'
        );

        $this->expectException(McpConnectionException::class);

        $transport->connect();
    }

    public function testConnectFailsWhenProcessExitsImmediately(): void
    {
        // Create a script that exits immediately with error
        $this->createMockServer('
            fwrite(STDERR, "Initialization failed");
            exit(1);
        ');

        $transport = new StdioTransport(
            command: 'php',
            args: [$this->mockServerScript],
            serverName: 'failing-server'
        );

        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('Process exited immediately');

        $transport->connect();
    }

    public function testConnectAndDisconnectWithMockServer(): void
    {
        // Create a mock MCP server that responds to initialize
        $this->createMockServer('
            // Read from stdin
            while ($line = fgets(STDIN)) {
                $request = json_decode(trim($line), true);
                if ($request && isset($request["method"])) {
                    if ($request["method"] === "initialize") {
                        $response = [
                            "jsonrpc" => "2.0",
                            "id" => $request["id"],
                            "result" => [
                                "protocolVersion" => "2024-11-05",
                                "capabilities" => [],
                                "serverInfo" => ["name" => "mock-server", "version" => "1.0.0"]
                            ]
                        ];
                        echo json_encode($response) . "\n";
                        flush();
                    } elseif ($request["method"] === "notifications/initialized") {
                        // Notification, no response needed
                    }
                }
            }
        ');

        $transport = new StdioTransport(
            command: 'php',
            args: [$this->mockServerScript],
            timeout: 5,
            serverName: 'mock-server'
        );

        $transport->connect();
        $this->assertTrue($transport->isConnected());

        $transport->disconnect();
        $this->assertFalse($transport->isConnected());
    }

    public function testRequestWithMockServer(): void
    {
        // Create a mock server that handles tools/list
        $this->createMockServer('
            while ($line = fgets(STDIN)) {
                $request = json_decode(trim($line), true);
                if (!$request || !isset($request["method"])) continue;

                $response = null;

                if ($request["method"] === "initialize") {
                    $response = [
                        "jsonrpc" => "2.0",
                        "id" => $request["id"],
                        "result" => ["protocolVersion" => "2024-11-05"]
                    ];
                } elseif ($request["method"] === "tools/list") {
                    $response = [
                        "jsonrpc" => "2.0",
                        "id" => $request["id"],
                        "result" => [
                            "tools" => [
                                ["name" => "test_tool", "description" => "A test tool"]
                            ]
                        ]
                    ];
                }

                if ($response) {
                    echo json_encode($response) . "\n";
                    flush();
                }
            }
        ');

        $transport = new StdioTransport(
            command: 'php',
            args: [$this->mockServerScript],
            timeout: 5
        );

        $transport->connect();

        $result = $transport->request('tools/list', []);

        $this->assertArrayHasKey('tools', $result);
        $this->assertEquals('test_tool', $result['tools'][0]['name']);

        $transport->disconnect();
    }

    public function testRequestWithJsonRpcError(): void
    {
        // Create a mock server that returns an error
        $this->createMockServer('
            while ($line = fgets(STDIN)) {
                $request = json_decode(trim($line), true);
                if (!$request || !isset($request["method"])) continue;

                if ($request["method"] === "initialize") {
                    $response = [
                        "jsonrpc" => "2.0",
                        "id" => $request["id"],
                        "result" => ["protocolVersion" => "2024-11-05"]
                    ];
                } elseif ($request["method"] === "error/method") {
                    $response = [
                        "jsonrpc" => "2.0",
                        "id" => $request["id"],
                        "error" => [
                            "code" => -32600,
                            "message" => "Invalid method",
                            "data" => null
                        ]
                    ];
                } else {
                    continue;
                }

                echo json_encode($response) . "\n";
                flush();
            }
        ');

        $transport = new StdioTransport(
            command: 'php',
            args: [$this->mockServerScript],
            timeout: 5
        );

        $transport->connect();

        $this->expectException(McpServerException::class);
        $this->expectExceptionMessage('Invalid method');

        try {
            $transport->request('error/method', []);
        } finally {
            $transport->disconnect();
        }
    }

    public function testRequestThrowsWhenNotConnected(): void
    {
        $transport = new StdioTransport(command: 'echo');

        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessage('Not connected');

        $transport->request('test/method', []);
    }

    public function testNotifyDoesNotThrowWhenNotConnected(): void
    {
        $transport = new StdioTransport(command: 'echo');

        // Should not throw
        $transport->notify('test/notification', ['data' => 'value']);

        $this->assertTrue(true);
    }

    public function testNotifyWithMockServer(): void
    {
        // Create a mock server
        $this->createMockServer('
            while ($line = fgets(STDIN)) {
                $request = json_decode(trim($line), true);
                if (!$request || !isset($request["method"])) continue;

                if ($request["method"] === "initialize") {
                    $response = [
                        "jsonrpc" => "2.0",
                        "id" => $request["id"],
                        "result" => ["protocolVersion" => "2024-11-05"]
                    ];
                    echo json_encode($response) . "\n";
                    flush();
                }
                // Other methods are just received, no response for notifications
            }
        ');

        $transport = new StdioTransport(
            command: 'php',
            args: [$this->mockServerScript],
            timeout: 5
        );

        $transport->connect();

        // Should not throw
        $transport->notify('notifications/progress', ['progress' => 50]);

        $transport->disconnect();

        $this->assertTrue(true);
    }

    public function testDisconnectCleansUpResources(): void
    {
        $this->createMockServer('
            while ($line = fgets(STDIN)) {
                $request = json_decode(trim($line), true);
                if (!$request || !isset($request["method"])) continue;

                if ($request["method"] === "initialize") {
                    echo json_encode([
                        "jsonrpc" => "2.0",
                        "id" => $request["id"],
                        "result" => ["protocolVersion" => "2024-11-05"]
                    ]) . "\n";
                    flush();
                }
            }
        ');

        $transport = new StdioTransport(
            command: 'php',
            args: [$this->mockServerScript],
            timeout: 5
        );

        $transport->connect();
        $this->assertTrue($transport->isConnected());

        $transport->disconnect();
        $this->assertFalse($transport->isConnected());

        // Calling disconnect again should be safe
        $transport->disconnect();
        $this->assertFalse($transport->isConnected());
    }

    public function testIsConnectedReturnsFalseAfterProcessTerminates(): void
    {
        // Create a server that exits after initialization
        $this->createMockServer('
            $line = fgets(STDIN);
            $request = json_decode(trim($line), true);
            if ($request && $request["method"] === "initialize") {
                echo json_encode([
                    "jsonrpc" => "2.0",
                    "id" => $request["id"],
                    "result" => ["protocolVersion" => "2024-11-05"]
                ]) . "\n";
                flush();
            }
            // Read one more line (notifications/initialized) then exit
            fgets(STDIN);
            exit(0);
        ');

        $transport = new StdioTransport(
            command: 'php',
            args: [$this->mockServerScript],
            timeout: 5
        );

        $transport->connect();

        // Give the process time to exit
        usleep(200000); // 200ms

        // isConnected should detect the process is no longer running
        $this->assertFalse($transport->isConnected());
    }

    public function testRequestTimeoutThrowsException(): void
    {
        // Create a server that never responds to tools/list
        $this->createMockServer('
            while ($line = fgets(STDIN)) {
                $request = json_decode(trim($line), true);
                if (!$request || !isset($request["method"])) continue;

                if ($request["method"] === "initialize") {
                    echo json_encode([
                        "jsonrpc" => "2.0",
                        "id" => $request["id"],
                        "result" => ["protocolVersion" => "2024-11-05"]
                    ]) . "\n";
                    flush();
                }
                // Other requests: do nothing (simulate timeout)
            }
        ');

        $transport = new StdioTransport(
            command: 'php',
            args: [$this->mockServerScript],
            timeout: 1, // 1 second timeout
            serverName: 'slow-server'
        );

        $transport->connect();

        $this->expectException(McpTransportException::class);
        // Could be "Timeout waiting for response" or "Max iterations reached"

        try {
            $transport->request('tools/list', []);
        } finally {
            $transport->disconnect();
        }
    }

    public function testTryParseResponseWithMultipleLines(): void
    {
        $transport = new StdioTransport(command: 'echo');

        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('tryParseResponse');
        $method->setAccessible(true);

        $buffer = "invalid json\n";
        $buffer .= json_encode(['jsonrpc' => '2.0', 'id' => 5, 'result' => ['found' => true]]) . "\n";
        $buffer .= "more invalid\n";

        $result = $method->invoke($transport, $buffer, 5);

        $this->assertNotNull($result);
        $this->assertTrue($result['result']['found']);
    }

    public function testTryParseResponseReturnsNullWhenNoMatch(): void
    {
        $transport = new StdioTransport(command: 'echo');

        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('tryParseResponse');
        $method->setAccessible(true);

        $buffer = json_encode(['jsonrpc' => '2.0', 'id' => 999, 'result' => []]) . "\n";

        $result = $method->invoke($transport, $buffer, 1);

        $this->assertNull($result);
    }

    public function testTryParseResponseWithEmptyLines(): void
    {
        $transport = new StdioTransport(command: 'echo');

        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('tryParseResponse');
        $method->setAccessible(true);

        $buffer = "\n\n\n" . json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]]) . "\n\n";

        $result = $method->invoke($transport, $buffer, 1);

        $this->assertNotNull($result);
        $this->assertTrue($result['result']['ok']);
    }

    public function testHandleJsonRpcResponseWithError(): void
    {
        $transport = new StdioTransport(command: 'echo');

        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('handleJsonRpcResponse');
        $method->setAccessible(true);

        $this->expectException(McpServerException::class);

        $method->invoke($transport, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => -32700,
                'message' => 'Parse error',
            ],
        ]);
    }

    public function testHandleJsonRpcResponseWithResult(): void
    {
        $transport = new StdioTransport(command: 'echo');

        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('handleJsonRpcResponse');
        $method->setAccessible(true);

        $result = $method->invoke($transport, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['data' => 'value'],
        ]);

        $this->assertEquals(['data' => 'value'], $result);
    }

    public function testHandleJsonRpcResponseWithEmptyResult(): void
    {
        $transport = new StdioTransport(command: 'echo');

        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('handleJsonRpcResponse');
        $method->setAccessible(true);

        $result = $method->invoke($transport, [
            'jsonrpc' => '2.0',
            'id' => 1,
            // No result key
        ]);

        $this->assertEquals([], $result);
    }

    public function testDestructorCallsDisconnect(): void
    {
        $this->createMockServer('
            while ($line = fgets(STDIN)) {
                $request = json_decode(trim($line), true);
                if (!$request || !isset($request["method"])) continue;

                if ($request["method"] === "initialize") {
                    echo json_encode([
                        "jsonrpc" => "2.0",
                        "id" => $request["id"],
                        "result" => ["protocolVersion" => "2024-11-05"]
                    ]) . "\n";
                    flush();
                }
            }
        ');

        $transport = new StdioTransport(
            command: 'php',
            args: [$this->mockServerScript],
            timeout: 5
        );

        $transport->connect();
        $this->assertTrue($transport->isConnected());

        // Unset to trigger destructor
        unset($transport);

        // If we get here without hanging, destructor worked
        $this->assertTrue(true);
    }

    public function testRequestWithParams(): void
    {
        $receivedParams = null;

        $this->createMockServer('
            while ($line = fgets(STDIN)) {
                $request = json_decode(trim($line), true);
                if (!$request || !isset($request["method"])) continue;

                if ($request["method"] === "initialize") {
                    echo json_encode([
                        "jsonrpc" => "2.0",
                        "id" => $request["id"],
                        "result" => ["protocolVersion" => "2024-11-05"]
                    ]) . "\n";
                } elseif ($request["method"] === "tools/call") {
                    // Echo back the params we received
                    echo json_encode([
                        "jsonrpc" => "2.0",
                        "id" => $request["id"],
                        "result" => ["received_params" => $request["params"]]
                    ]) . "\n";
                }
                flush();
            }
        ');

        $transport = new StdioTransport(
            command: 'php',
            args: [$this->mockServerScript],
            timeout: 5
        );

        $transport->connect();

        $result = $transport->request('tools/call', [
            'name' => 'my_tool',
            'arguments' => ['key' => 'value'],
        ]);

        $this->assertArrayHasKey('received_params', $result);
        $params = (array) $result['received_params'];
        $this->assertEquals('my_tool', $params['name']);

        $transport->disconnect();
    }

    public function testConnectWithEnvironmentVariables(): void
    {
        $this->createMockServer('
            // Output env var value
            $envValue = getenv("TEST_VAR");

            while ($line = fgets(STDIN)) {
                $request = json_decode(trim($line), true);
                if (!$request || !isset($request["method"])) continue;

                if ($request["method"] === "initialize") {
                    echo json_encode([
                        "jsonrpc" => "2.0",
                        "id" => $request["id"],
                        "result" => [
                            "protocolVersion" => "2024-11-05",
                            "env_test" => $envValue
                        ]
                    ]) . "\n";
                    flush();
                }
            }
        ');

        $transport = new StdioTransport(
            command: 'php',
            args: [$this->mockServerScript],
            env: ['TEST_VAR' => 'test_value'],
            timeout: 5
        );

        $transport->connect();
        $this->assertTrue($transport->isConnected());

        $transport->disconnect();
    }

    // ========== Additional Tests for Coverage ==========

    public function testImplementsTransportInterface(): void
    {
        $transport = new StdioTransport(command: 'echo');

        $this->assertInstanceOf(
            \Milpa\McpClient\Contracts\TransportInterface::class,
            $transport
        );
    }

    public function testHandleJsonRpcResponseWithErrorMissingMessage(): void
    {
        $transport = new StdioTransport(command: 'echo');

        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('handleJsonRpcResponse');
        $method->setAccessible(true);

        $this->expectException(McpServerException::class);
        $this->expectExceptionMessage('Unknown error');

        $method->invoke($transport, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => -32700,
                // No message
            ],
        ]);
    }

    public function testHandleJsonRpcResponseWithErrorData(): void
    {
        $transport = new StdioTransport(command: 'echo', serverName: 'test-server');

        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('handleJsonRpcResponse');
        $method->setAccessible(true);

        try {
            $method->invoke($transport, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [
                    'code' => -32600,
                    'message' => 'Invalid request',
                    'data' => ['field' => 'method', 'reason' => 'missing'],
                ],
            ]);
            $this->fail('Expected McpServerException');
        } catch (McpServerException $e) {
            $this->assertStringContainsString('Invalid request', $e->getMessage());
            $this->assertEquals(-32600, $e->getCode());
        }
    }

    public function testConstructorWithWorkingDirectory(): void
    {
        $transport = new StdioTransport(
            command: 'php',
            args: ['-v'],
            workingDir: '/tmp'
        );

        $this->assertFalse($transport->isConnected());
    }

    public function testTryParseResponseWithMalformedJson(): void
    {
        $transport = new StdioTransport(command: 'echo');

        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('tryParseResponse');
        $method->setAccessible(true);

        $buffer = "{not valid json\n{\"jsonrpc\": \"2.0\", \"id\": 1}\n";

        // Should skip malformed JSON and find the valid one without id match
        $result = $method->invoke($transport, $buffer, 99);

        $this->assertNull($result);
    }

    public function testTryParseResponseWithNonArrayJson(): void
    {
        $transport = new StdioTransport(command: 'echo');

        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('tryParseResponse');
        $method->setAccessible(true);

        $buffer = "\"just a string\"\n123\nnull\n";

        $result = $method->invoke($transport, $buffer, 1);

        $this->assertNull($result);
    }

    public function testDisconnectWhenAlreadyDisconnected(): void
    {
        $transport = new StdioTransport(command: 'echo');

        // Call disconnect multiple times - should be safe
        $transport->disconnect();
        $transport->disconnect();
        $transport->disconnect();

        $this->assertFalse($transport->isConnected());
    }

    public function testNotifyWithEmptyParams(): void
    {
        $this->createMockServer('
            while ($line = fgets(STDIN)) {
                $request = json_decode(trim($line), true);
                if (!$request || !isset($request["method"])) continue;

                if ($request["method"] === "initialize") {
                    echo json_encode([
                        "jsonrpc" => "2.0",
                        "id" => $request["id"],
                        "result" => ["protocolVersion" => "2024-11-05"]
                    ]) . "\n";
                    flush();
                }
            }
        ');

        $transport = new StdioTransport(
            command: 'php',
            args: [$this->mockServerScript],
            timeout: 5
        );

        $transport->connect();

        // Empty params should work
        $transport->notify('test/notification', []);

        $transport->disconnect();

        $this->assertTrue(true);
    }

    public function testMultipleSequentialRequests(): void
    {
        $this->createMockServer('
            $counter = 0;
            while ($line = fgets(STDIN)) {
                $request = json_decode(trim($line), true);
                if (!$request || !isset($request["method"])) continue;

                if ($request["method"] === "initialize") {
                    $response = [
                        "jsonrpc" => "2.0",
                        "id" => $request["id"],
                        "result" => ["protocolVersion" => "2024-11-05"]
                    ];
                } elseif ($request["method"] === "counter/get") {
                    $counter++;
                    $response = [
                        "jsonrpc" => "2.0",
                        "id" => $request["id"],
                        "result" => ["count" => $counter]
                    ];
                } else {
                    continue;
                }

                echo json_encode($response) . "\n";
                flush();
            }
        ');

        $transport = new StdioTransport(
            command: 'php',
            args: [$this->mockServerScript],
            timeout: 5
        );

        $transport->connect();

        $result1 = $transport->request('counter/get', []);
        $result2 = $transport->request('counter/get', []);
        $result3 = $transport->request('counter/get', []);

        $this->assertEquals(1, $result1['count']);
        $this->assertEquals(2, $result2['count']);
        $this->assertEquals(3, $result3['count']);

        $transport->disconnect();
    }
}
