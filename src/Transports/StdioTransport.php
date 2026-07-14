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

namespace Milpa\McpClient\Transports;

use Milpa\McpClient\Contracts\McpConnectionException;
use Milpa\McpClient\Contracts\McpServerException;
use Milpa\McpClient\Contracts\McpTransportException;
use Milpa\McpClient\Contracts\TransportInterface;

/**
 * Stdio Transport for MCP servers.
 *
 * This transport spawns a subprocess and communicates via stdin/stdout.
 * Used for local MCP servers like @anthropic/mcp-server-* packages.
 *
 * Communication flow:
 * 1. Spawn process with given command
 * 2. Send JSON-RPC requests via stdin (newline-delimited)
 * 3. Read JSON-RPC responses from stdout
 *
 * @see https://modelcontextprotocol.io/docs/concepts/transports#stdio
 */
class StdioTransport implements TransportInterface
{
    /** @var resource|closed-resource|null Process handle */
    private mixed $process = null;

    /** @var resource|null stdin pipe for writing to process */
    private mixed $stdin = null;

    /** @var resource|null stdout pipe for reading from process */
    private mixed $stdout = null;

    /** @var resource|null stderr pipe for reading error output */
    private mixed $stderr = null;

    private bool $connected = false;
    private int $requestId = 0;

    /**
     * @param string                $command    Command to execute
     * @param array<int, string>    $args       Command arguments
     * @param array<string, string> $env        Environment variables
     * @param string|null           $workingDir Working directory for the process
     * @param int                   $timeout    Request timeout in seconds
     * @param string|null           $serverName Server name for error messages
     */
    public function __construct(
        private readonly string $command,
        private readonly array $args = [],
        private readonly array $env = [],
        private readonly ?string $workingDir = null,
        private readonly int $timeout = 30,
        private readonly ?string $serverName = null,
    ) {
    }

    /**
     * Spawn the configured command as a subprocess and run the MCP handshake over its pipes.
     */
    public function connect(): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        // Build command
        $cmd = $this->buildCommand();

        // Merge environment
        $env = array_merge($_ENV, $this->env);

        // Spawn process
        $this->process = proc_open(
            $cmd,
            $descriptorSpec,
            $pipes,
            $this->workingDir,
            $env
        );

        if (!is_resource($this->process)) {
            throw new McpConnectionException(
                "Failed to spawn process: {$cmd}",
                $this->serverName
            );
        }

        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];

        // Set non-blocking mode for stdout
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);

        // Wait a moment for process to start
        usleep(100000); // 100ms

        // Check if process is still running
        $status = proc_get_status($this->process);
        if (!$status['running']) {
            $stderr = stream_get_contents($this->stderr);
            throw new McpConnectionException(
                "Process exited immediately: {$stderr}",
                $this->serverName
            );
        }

        try {
            // Send initialize request
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
            $this->disconnect();
            throw new McpConnectionException(
                "Failed to initialize: " . $e->getMessage(),
                $this->serverName,
                0,
                $e
            );
        }
    }

    /**
     * Close the pipes and terminate the subprocess.
     */
    public function disconnect(): void
    {
        if ($this->stdin) {
            fclose($this->stdin);
            $this->stdin = null;
        }

        if ($this->stdout) {
            fclose($this->stdout);
            $this->stdout = null;
        }

        if ($this->stderr) {
            fclose($this->stderr);
            $this->stderr = null;
        }

        if ($this->process) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }

        $this->connected = false;
    }

    /**
     * Check whether the subprocess is still alive.
     */
    public function isConnected(): bool
    {
        if (!$this->connected || !$this->process) {
            return false;
        }

        $status = proc_get_status($this->process);
        return $status['running'];
    }

    /**
     * Write a JSON-RPC request to the subprocess's stdin and block for its stdout response.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function request(string $method, array $params = []): array
    {
        if (!$this->process || !$this->stdin || !$this->stdout) {
            throw new McpTransportException(
                "Not connected",
                $this->serverName
            );
        }

        $requestId = ++$this->requestId;

        $payload = [
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => $method,
            'params' => (object) $params,
        ];

        // Send request (newline-delimited JSON)
        $json = json_encode($payload) . "\n";
        $written = fwrite($this->stdin, $json);

        if ($written === false) {
            throw new McpTransportException(
                "Failed to write to stdin",
                $this->serverName
            );
        }

        fflush($this->stdin);

        // Read response with timeout
        $response = $this->readResponse($requestId);

        return $this->handleJsonRpcResponse($response);
    }

    /**
     * Write a JSON-RPC notification to the subprocess's stdin; no response is awaited.
     *
     * @param array<string, mixed> $params
     */
    public function notify(string $method, array $params = []): void
    {
        if (!$this->process || !$this->stdin) {
            return;
        }

        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => (object) $params,
        ];

        $json = json_encode($payload) . "\n";
        fwrite($this->stdin, $json);
        fflush($this->stdin);
    }

    /**
     * Read response from stdout with timeout.
     *
     * @param int $expectedId The request ID to wait for
     *
     * @return array<string, mixed> The JSON-RPC response
     *
     * @throws McpTransportException If timeout or process termination occurs
     */
    private function readResponse(int $expectedId): array
    {
        $startTime = microtime(true);
        $buffer = '';
        $maxIterations = ($this->timeout * 1000) / 10; // Convert to 10ms iterations
        $iterations = 0;

        while ($iterations++ < $maxIterations) {
            // Check timeout with microsecond precision
            if ((microtime(true) - $startTime) > $this->timeout) {
                throw new McpTransportException(
                    "Timeout waiting for response after {$this->timeout} seconds",
                    $this->serverName
                );
            }

            // Check if process is still running
            if (!is_resource($this->process)) {
                throw new McpTransportException(
                    "Process handle is invalid",
                    $this->serverName
                );
            }

            $status = proc_get_status($this->process);
            if (!$status['running']) {
                // Try to get any remaining output before failing
                $remainingOutput = is_resource($this->stdout) ? stream_get_contents($this->stdout) : '';
                $stderr = is_resource($this->stderr) ? stream_get_contents($this->stderr) : '';

                // Check if we got a response in the remaining output
                if ($remainingOutput) {
                    $buffer .= $remainingOutput;
                    $result = $this->tryParseResponse($buffer, $expectedId);
                    if ($result !== null) {
                        return $result;
                    }
                }

                throw new McpTransportException(
                    "Process terminated unexpectedly: {$stderr}",
                    $this->serverName
                );
            }

            // Read available data
            if (!is_resource($this->stdout)) {
                throw new McpTransportException(
                    "stdout pipe is not available",
                    $this->serverName
                );
            }

            $chunk = fread($this->stdout, 8192);

            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;

                $result = $this->tryParseResponse($buffer, $expectedId);
                if ($result !== null) {
                    return $result;
                }

                // Keep only incomplete last line in buffer
                $lastNewline = strrpos($buffer, "\n");
                if ($lastNewline !== false) {
                    $buffer = substr($buffer, $lastNewline + 1);
                }
            }

            // Small delay to avoid busy waiting
            usleep(10000); // 10ms
        }

        throw new McpTransportException(
            "Max iterations reached waiting for response",
            $this->serverName
        );
    }

    /**
     * Try to parse a response from the buffer.
     *
     * @param string $buffer     The accumulated buffer
     * @param int    $expectedId The request ID to look for
     *
     * @return array<string, mixed>|null The response if found, null otherwise
     */
    private function tryParseResponse(string $buffer, int $expectedId): ?array
    {
        $lines = explode("\n", $buffer);

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            $data = json_decode($line, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                // Check if this is our response
                if (isset($data['id']) && $data['id'] === $expectedId) {
                    return $data;
                }
            }
        }

        return null;
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
        if (isset($response['error'])) {
            $error = $response['error'];
            throw new McpServerException(
                $error['message'] ?? 'Unknown error',
                $error['code'] ?? -1,
                $error['data'] ?? null,
                $this->serverName
            );
        }

        return $response['result'] ?? [];
    }

    /**
     * Build the command string.
     */
    private function buildCommand(): string
    {
        $parts = [$this->command];

        foreach ($this->args as $arg) {
            // Escape arguments
            $parts[] = escapeshellarg($arg);
        }

        return implode(' ', $parts);
    }

    /**
     * Destructor ensures process is terminated.
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
