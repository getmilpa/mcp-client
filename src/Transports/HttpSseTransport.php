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

namespace Milpa\McpClient\Transports;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Milpa\McpClient\Contracts\McpConnectionException;
use Milpa\McpClient\Contracts\McpServerException;
use Milpa\McpClient\Contracts\McpTransportException;
use Milpa\McpClient\Contracts\TransportInterface;

/**
 * HTTP/SSE Transport for MCP servers.
 *
 * This transport is used for remote MCP servers like Cloudflare's.
 * Communication flow:
 * 1. Client sends JSON-RPC request via HTTP POST
 * 2. Server responds via Server-Sent Events (SSE) or direct JSON response
 *
 * @see https://modelcontextprotocol.io/docs/concepts/transports#http-with-sse
 */
class HttpSseTransport implements TransportInterface
{
    private Client $client;
    private bool $connected = false;
    private int $requestId = 0;

    /**
     * @param string                $baseUrl    Base URL of the MCP server
     * @param array<string, string> $headers    Additional HTTP headers
     * @param int                   $timeout    Request timeout in seconds
     * @param string|null           $serverName Server name for error messages
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly array $headers = [],
        private readonly int $timeout = 30,
        private readonly ?string $serverName = null,
    ) {
        $defaultHeaders = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ];

        $this->client = new Client([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
            'timeout' => $this->timeout,
            'headers' => array_merge($defaultHeaders, $this->headers),
            'http_errors' => false,
        ]);
    }

    /**
     * Verify the server is reachable by running the MCP `initialize` handshake over HTTP.
     */
    public function connect(): void
    {
        try {
            // Test connection by sending initialize request
            $response = $this->request('initialize', [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [
                    'roots' => ['listChanged' => true],
                ],
                'clientInfo' => [
                    'name' => 'milpa-mcp-client',
                    'version' => '1.0.0',
                ],
            ]);

            // Send initialized notification
            $this->notify('notifications/initialized', []);

            $this->connected = true;

        } catch (\Exception $e) {
            throw new McpConnectionException(
                "Failed to connect: " . $e->getMessage(),
                $this->serverName,
                0,
                $e
            );
        }
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * POST a JSON-RPC request and parse the reply, whether it comes back as SSE or plain JSON.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function request(string $method, array $params = []): array
    {
        $requestId = ++$this->requestId;

        $payload = [
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => $method,
            'params' => (object) $params, // Force object even if empty
        ];

        try {
            $response = $this->client->post('', [
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $contentType = $response->getHeaderLine('Content-Type');
            $body = $response->getBody()->getContents();

            // Handle SSE response
            if (str_contains($contentType, 'text/event-stream')) {
                return $this->parseSseResponse($body, $requestId);
            }

            // Handle direct JSON response
            if (str_contains($contentType, 'application/json')) {
                return $this->parseJsonResponse($body, $requestId);
            }

            // Handle error status codes
            if ($statusCode >= 400) {
                throw new McpTransportException(
                    "HTTP error {$statusCode}: {$body}",
                    $this->serverName,
                    $statusCode
                );
            }

            throw new McpTransportException(
                "Unexpected content type: {$contentType}",
                $this->serverName
            );

        } catch (GuzzleException $e) {
            throw new McpTransportException(
                "HTTP request failed: " . $e->getMessage(),
                $this->serverName,
                0,
                $e
            );
        }
    }

    /**
     * POST a JSON-RPC notification; the response, if any, is discarded.
     *
     * @param array<string, mixed> $params
     */
    public function notify(string $method, array $params = []): void
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => (object) $params,
        ];

        try {
            $this->client->post('', [
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            // Notifications don't require response, but log error
            // Could add logging here if needed
        }
    }

    /**
     * Parse Server-Sent Events response.
     *
     * @return array<string, mixed>
     */
    private function parseSseResponse(string $body, int $expectedId): array
    {
        $events = $this->parseSseEvents($body);

        foreach ($events as $event) {
            if ($event['event'] === 'message') {
                $data = json_decode($event['data'], true);

                if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                    continue;
                }

                // Check if this is our response
                if (isset($data['id']) && $data['id'] === $expectedId) {
                    return $this->handleJsonRpcResponse($data);
                }
            }
        }

        throw new McpTransportException(
            "No valid response received for request {$expectedId}",
            $this->serverName
        );
    }

    /**
     * Parse SSE event stream into individual events.
     *
     * @return list<array{event: string, data: string, id?: string}>
     */
    private function parseSseEvents(string $body): array
    {
        $events = [];
        $currentEvent = ['event' => 'message', 'data' => ''];

        $lines = explode("\n", $body);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                // Empty line marks end of event
                if (!empty($currentEvent['data'])) {
                    $events[] = $currentEvent;
                }
                $currentEvent = ['event' => 'message', 'data' => ''];
                continue;
            }

            if (str_starts_with($line, 'event:')) {
                $currentEvent['event'] = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data = trim(substr($line, 5));
                $currentEvent['data'] .= $data;
            } elseif (str_starts_with($line, 'id:')) {
                $currentEvent['id'] = trim(substr($line, 3));
            }
        }

        // Don't forget last event if no trailing newline
        if (!empty($currentEvent['data'])) {
            $events[] = $currentEvent;
        }

        return $events;
    }

    /**
     * Parse direct JSON response.
     *
     * @return array<string, mixed>
     */
    private function parseJsonResponse(string $body, int $expectedId): array
    {
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new McpTransportException(
                "Invalid JSON response: " . json_last_error_msg(),
                $this->serverName
            );
        }

        return $this->handleJsonRpcResponse($data);
    }

    /**
     * Handle JSON-RPC response (success or error).
     *
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function handleJsonRpcResponse(array $response): array
    {
        // Check for JSON-RPC error
        if (isset($response['error'])) {
            $error = $response['error'];
            throw new McpServerException(
                $error['message'] ?? 'Unknown error',
                $error['code'] ?? -1,
                $error['data'] ?? null,
                $this->serverName
            );
        }

        // Return result
        return $response['result'] ?? [];
    }
}
