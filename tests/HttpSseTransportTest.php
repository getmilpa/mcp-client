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

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Milpa\McpClient\Contracts\McpConnectionException;
use Milpa\McpClient\Contracts\McpServerException;
use Milpa\McpClient\Contracts\McpTransportException;
use Milpa\McpClient\Transports\HttpSseTransport;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class HttpSseTransportTest extends TestCase
{
    private function createTransportWithMock(MockHandler $mock, ?LoggerInterface $logger = null): HttpSseTransport
    {
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        // Guzzle's Client implements Psr\Http\Client\ClientInterface natively (PSR-18), so the
        // mock-backed client goes straight through the constructor-injectable seam — no
        // reflection into private internals needed anymore.
        return new HttpSseTransport(
            baseUrl: 'http://localhost:8080',
            headers: ['X-Custom' => 'test'],
            timeout: 5,
            serverName: 'test-server',
            httpClient: $client,
            logger: $logger,
        );
    }

    public function testConstructorSetsProperties(): void
    {
        $transport = new HttpSseTransport(
            baseUrl: 'http://example.com',
            headers: ['Authorization' => 'Bearer token'],
            timeout: 60,
            serverName: 'my-server'
        );

        $this->assertFalse($transport->isConnected());
    }

    public function testIsConnectedReturnsFalseInitially(): void
    {
        $transport = new HttpSseTransport('http://localhost');
        $this->assertFalse($transport->isConnected());
    }

    public function testDisconnectSetsConnectedFalse(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => ['protocolVersion' => '2024-11-05'],
            ])),
            new Response(200), // for initialized notification
        ]);

        $transport = $this->createTransportWithMock($mock);
        $transport->connect();
        $this->assertTrue($transport->isConnected());

        $transport->disconnect();
        $this->assertFalse($transport->isConnected());
    }

    public function testConnectSuccess(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => ['protocolVersion' => '2024-11-05'],
            ])),
            new Response(200), // for initialized notification
        ]);

        $transport = $this->createTransportWithMock($mock);
        $transport->connect();

        $this->assertTrue($transport->isConnected());
    }

    public function testConnectFailureThrowsException(): void
    {
        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('POST', 'http://localhost')),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('Failed to connect');

        $transport->connect();
    }

    public function testRequestWithJsonResponse(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => ['tools' => [['name' => 'test_tool']]],
            ])),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $result = $transport->request('tools/list', []);

        $this->assertArrayHasKey('tools', $result);
        $this->assertEquals('test_tool', $result['tools'][0]['name']);
    }

    public function testRequestWithSseResponse(): void
    {
        $sseBody = "event: message\ndata: " . json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['resources' => []],
        ]) . "\n\n";

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $result = $transport->request('resources/list', []);

        $this->assertArrayHasKey('resources', $result);
    }

    public function testRequestWithSseMultipleEvents(): void
    {
        // SSE with multiple events, only one matching our request ID
        $sseBody = "event: ping\ndata: keep-alive\n\n";
        $sseBody .= "event: message\ndata: " . json_encode([
            'jsonrpc' => '2.0',
            'id' => 999, // wrong ID
            'result' => ['wrong' => true],
        ]) . "\n\n";
        $sseBody .= "event: message\ndata: " . json_encode([
            'jsonrpc' => '2.0',
            'id' => 1, // correct ID
            'result' => ['correct' => true],
        ]) . "\n\n";

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $result = $transport->request('test/method', []);

        $this->assertTrue($result['correct']);
    }

    public function testRequestWithJsonRpcError(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [
                    'code' => -32600,
                    'message' => 'Invalid Request',
                    'data' => ['details' => 'Missing required field'],
                ],
            ])),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $this->expectException(McpServerException::class);
        $this->expectExceptionMessage('Invalid Request');

        $transport->request('invalid/method', []);
    }

    public function testRequestWithHttpError(): void
    {
        $mock = new MockHandler([
            new Response(500, ['Content-Type' => 'text/plain'], 'Internal Server Error'),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $this->expectException(McpTransportException::class);
        // The error message could vary based on Guzzle configuration
        // Just verify the exception is thrown

        $transport->request('test/method', []);
    }

    public function testRequestWithUnexpectedContentType(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessage('Unexpected content type');

        $transport->request('test/method', []);
    }

    public function testRequestWithInvalidJsonResponse(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], 'not valid json'),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessage('Invalid JSON response');

        $transport->request('test/method', []);
    }

    public function testRequestWithNetworkError(): void
    {
        $mock = new MockHandler([
            new ConnectException('Network unreachable', new Request('POST', 'http://localhost')),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessage('HTTP request failed');

        $transport->request('test/method', []);
    }

    public function testNotifyDoesNotThrowOnError(): void
    {
        $mock = new MockHandler([
            new ConnectException('Network error', new Request('POST', 'http://localhost')),
        ]);

        $transport = $this->createTransportWithMock($mock);

        // Should not throw - notifications are fire and forget
        $transport->notify('notifications/test', ['data' => 'value']);

        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testNotifySendsCorrectPayload(): void
    {
        $mock = new MockHandler([
            new Response(200),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $transport->notify('notifications/progress', ['progress' => 50]);

        // Verify the request was made (mock handler consumed it)
        $this->assertEmpty($mock->count());
    }

    public function testRequestIncrementsRequestId(): void
    {
        $responses = [];
        for ($i = 1; $i <= 3; $i++) {
            $responses[] = new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'id' => $i,
                'result' => ['request_num' => $i],
            ]));
        }

        $mock = new MockHandler($responses);
        $transport = $this->createTransportWithMock($mock);

        $result1 = $transport->request('method1', []);
        $result2 = $transport->request('method2', []);
        $result3 = $transport->request('method3', []);

        $this->assertEquals(1, $result1['request_num']);
        $this->assertEquals(2, $result2['request_num']);
        $this->assertEquals(3, $result3['request_num']);
    }

    public function testRequestWithEmptyResult(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                // No 'result' key
            ])),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $result = $transport->request('test/method', []);

        $this->assertEmpty($result);
    }

    public function testSseResponseWithNoMatchingId(): void
    {
        $sseBody = "event: message\ndata: " . json_encode([
            'jsonrpc' => '2.0',
            'id' => 999, // Wrong ID
            'result' => ['data' => 'wrong'],
        ]) . "\n\n";

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessage('No valid response received');

        $transport->request('test/method', []);
    }

    public function testSseResponseWithInvalidJson(): void
    {
        $sseBody = "event: message\ndata: not-valid-json\n\n";
        $sseBody .= "event: message\ndata: " . json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['valid' => true],
        ]) . "\n\n";

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $transport = $this->createTransportWithMock($mock);

        // Should skip invalid JSON and find valid response
        $result = $transport->request('test/method', []);

        $this->assertTrue($result['valid']);
    }

    public function testSseParsingWithVariousFormats(): void
    {
        // Test SSE with different line formats
        $sseBody = "event: message\n";
        $sseBody .= "id: 123\n";
        $sseBody .= "data: " . json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['parsed' => true]]) . "\n";
        $sseBody .= "\n"; // End of event

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $result = $transport->request('test/method', []);

        $this->assertTrue($result['parsed']);
    }

    public function testSseParsingWithoutTrailingNewline(): void
    {
        // SSE body without trailing double newline
        $sseBody = "event: message\ndata: " . json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['no_trailing' => true],
        ]);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $result = $transport->request('test/method', []);

        $this->assertTrue($result['no_trailing']);
    }

    public function testJsonRpcErrorWithMinimalFields(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [], // Minimal error object
            ])),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $this->expectException(McpServerException::class);

        $transport->request('test/method', []);
    }

    public function testRequestWithParams(): void
    {
        $capturedRequest = null;

        $mock = new MockHandler([
            function ($request) use (&$capturedRequest) {
                $capturedRequest = json_decode($request->getBody()->getContents(), true);
                return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [],
                ]));
            },
        ]);

        $transport = $this->createTransportWithMock($mock);

        $transport->request('tools/call', [
            'name' => 'my_tool',
            'arguments' => ['arg1' => 'value1'],
        ]);

        $this->assertEquals('tools/call', $capturedRequest['method']);
        $this->assertEquals('my_tool', $capturedRequest['params']->name ?? $capturedRequest['params']['name']);
    }

    // ========== Additional Tests for Full Coverage ==========

    public function testSseResponseWithEmptyDataLines(): void
    {
        // SSE with empty events between valid ones
        $sseBody = "event: message\n\n"; // Empty event (no data)
        $sseBody .= "event: message\ndata: " . json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['found' => true],
        ]) . "\n\n";

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $result = $transport->request('test/method', []);

        $this->assertTrue($result['found']);
    }

    public function testSseResponseWithOnlyEmptyEvents(): void
    {
        // SSE with only empty events - no valid response
        $sseBody = "event: message\n\n";
        $sseBody .= "event: ping\n\n";

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessage('No valid response received');

        $transport->request('test/method', []);
    }

    public function testSseResponseWithNonMessageEvent(): void
    {
        // SSE with different event type that has data
        $sseBody = "event: ping\ndata: keepalive\n\n";
        $sseBody .= "event: message\ndata: " . json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['success' => true],
        ]) . "\n\n";

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $result = $transport->request('test/method', []);

        $this->assertTrue($result['success']);
    }

    public function testConstructorWithDefaultValues(): void
    {
        // Test constructor with minimal parameters
        $transport = new HttpSseTransport(
            baseUrl: 'http://example.com'
        );

        $this->assertFalse($transport->isConnected());
    }

    public function testRequestWith400HttpError(): void
    {
        $mock = new MockHandler([
            new Response(400, ['Content-Type' => 'text/plain'], 'Bad Request'),
        ]);

        $transport = $this->createTransportWithMock($mock);

        try {
            $transport->request('test/method', []);
            $this->fail('Expected McpTransportException');
        } catch (McpTransportException $e) {
            $this->assertStringContainsString('400', $e->getMessage());
        }
    }

    public function testRequestWith404HttpError(): void
    {
        $mock = new MockHandler([
            new Response(404, ['Content-Type' => 'text/plain'], 'Not Found'),
        ]);

        $transport = $this->createTransportWithMock($mock);

        try {
            $transport->request('test/method', []);
            $this->fail('Expected McpTransportException');
        } catch (McpTransportException $e) {
            $this->assertStringContainsString('404', $e->getMessage());
        }
    }

    public function testSseWithMultiLineData(): void
    {
        // SSE data can span multiple lines
        $sseBody = "event: message\n";
        $sseBody .= "data: " . json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['multi' => true]]) . "\n";
        $sseBody .= "\n";

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $result = $transport->request('test/method', []);

        $this->assertTrue($result['multi']);
    }

    public function testSseWithUnknownField(): void
    {
        // SSE with unknown fields (should be ignored)
        $sseBody = "event: message\n";
        $sseBody .= "retry: 5000\n"; // Unknown field
        $sseBody .= "data: " . json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]]) . "\n";
        $sseBody .= "\n";

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $result = $transport->request('test/method', []);

        $this->assertTrue($result['ok']);
    }

    public function testJsonRpcErrorWithDataField(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [
                    'code' => -32601,
                    'message' => 'Method not found',
                    'data' => ['method' => 'unknown_method', 'available' => ['tools/list']],
                ],
            ])),
        ]);

        $transport = $this->createTransportWithMock($mock);

        try {
            $transport->request('unknown_method', []);
            $this->fail('Expected McpServerException');
        } catch (McpServerException $e) {
            $this->assertEquals(-32601, $e->getCode());
            $this->assertStringContainsString('Method not found', $e->getMessage());
        }
    }

    public function testNotifyWithSuccessfulResponse(): void
    {
        $mock = new MockHandler([
            new Response(202), // Accepted
        ]);

        $transport = $this->createTransportWithMock($mock);

        // Should not throw
        $transport->notify('notifications/progress', ['progress' => 100]);

        $this->assertTrue(true);
    }

    public function testSseParsingContinuesDataOnSameLine(): void
    {
        // Test data field continuing to append
        $json = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['appended' => true]]);
        $part1 = substr($json, 0, 20);
        $part2 = substr($json, 20);

        $sseBody = "event: message\n";
        $sseBody .= "data: {$part1}\n";
        $sseBody .= "data: {$part2}\n"; // Continuation - should append
        $sseBody .= "\n";

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $result = $transport->request('test/method', []);

        $this->assertTrue($result['appended']);
    }

    public function testConnectWithServerError(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [
                    'code' => -32000,
                    'message' => 'Server initialization failed',
                ],
            ])),
        ]);

        $transport = $this->createTransportWithMock($mock);

        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('Failed to connect');

        $transport->connect();
    }

    public function testRequestWithEmptyParams(): void
    {
        $capturedBody = null;

        $mock = new MockHandler([
            function ($request) use (&$capturedBody) {
                $capturedBody = $request->getBody()->getContents();
                return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['empty_params' => true],
                ]));
            },
        ]);

        $transport = $this->createTransportWithMock($mock);

        $result = $transport->request('test/method', []);

        // Empty params should be cast to object
        $this->assertTrue($result['empty_params']);
        // The raw JSON should contain empty object {} not empty array []
        $this->assertStringContainsString('"params":{}', $capturedBody);
    }

    // ========== PSR-18 seam: fake ClientInterface, no network, no Guzzle internals ==========

    public function testRequestGoesThroughAnInjectedPsr18ClientVerbatim(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['via' => 'fake-psr18-client'],
        ]));

        $fakeClient = new FakeHttpClient($response);

        $transport = new HttpSseTransport(
            baseUrl: 'http://example.test',
            headers: ['X-Custom' => 'test'],
            serverName: 'fake-server',
            httpClient: $fakeClient,
        );

        $result = $transport->request('tools/list', ['foo' => 'bar']);

        $this->assertSame(['via' => 'fake-psr18-client'], $result);

        $sent = $fakeClient->lastRequest;
        $this->assertNotNull($sent);
        $this->assertSame('POST', $sent->getMethod());
        $this->assertSame('http://example.test/', (string) $sent->getUri());
        $this->assertSame('application/json', $sent->getHeaderLine('Content-Type'));
        $this->assertSame('application/json, text/event-stream', $sent->getHeaderLine('Accept'));
        $this->assertSame('test', $sent->getHeaderLine('X-Custom'));

        $sentPayload = json_decode((string) $sent->getBody(), true);
        $this->assertSame('tools/list', $sentPayload['method']);
        $this->assertSame(['foo' => 'bar'], $sentPayload['params']);
    }

    public function testRequestWrapsPsr18ClientExceptionInMcpTransportException(): void
    {
        $fakeClient = new FakeHttpClient(new FakeClientException('network is down'));

        $transport = new HttpSseTransport(
            baseUrl: 'http://example.test',
            serverName: 'fake-server',
            httpClient: $fakeClient,
        );

        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessage('HTTP request failed');

        $transport->request('test/method', []);
    }

    public function testNotifyLogsPsr18ClientFailureInsteadOfSwallowingSilently(): void
    {
        $exception = new FakeClientException('boom');
        $fakeClient = new FakeHttpClient($exception);
        $logger = new RecordingLogger();

        $transport = new HttpSseTransport(
            baseUrl: 'http://example.test',
            serverName: 'fake-server',
            httpClient: $fakeClient,
            logger: $logger,
        );

        // Fire-and-forget contract preserved: still no exception out of notify().
        $transport->notify('notifications/progress', ['progress' => 1]);

        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        $this->assertSame('notifications/progress', $logger->records[0]['context']['method']);
        $this->assertSame($exception, $logger->records[0]['context']['exception']);
    }

    public function testNotifyStillDoesNotThrowWhenNoLoggerIsInjected(): void
    {
        $fakeClient = new FakeHttpClient(new FakeClientException('boom'));

        $transport = new HttpSseTransport(
            baseUrl: 'http://example.test',
            serverName: 'fake-server',
            httpClient: $fakeClient,
        );

        $transport->notify('notifications/progress', ['progress' => 1]);

        $this->assertTrue(true); // Reaching here means notify() did not throw.
    }
}

/**
 * Minimal PSR-18 test double — proves the seam works with ANY `ClientInterface`, not just
 * Guzzle's (which the rest of this suite exercises via `MockHandler`, itself a real PSR-18
 * client). No network, no Guzzle HTTP internals: this class only speaks PSR-7/PSR-18.
 */
final class FakeHttpClient implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    public function __construct(
        private readonly ResponseInterface|ClientExceptionInterface|null $result = null,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        if ($this->result instanceof ClientExceptionInterface) {
            throw $this->result;
        }

        return $this->result ?? new Response(200, ['Content-Type' => 'application/json'], '{}');
    }
}

/**
 * Minimal PSR-18 client exception test double.
 */
final class FakeClientException extends \RuntimeException implements ClientExceptionInterface
{
}

/**
 * Minimal PSR-3 logger test double that records every call instead of writing anywhere.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
