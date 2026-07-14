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
use Milpa\McpClient\Contracts\McpTool;

class McpToolTest extends TestCase
{
    public function testConstructor(): void
    {
        $tool = new McpTool(
            name: 'test_tool',
            description: 'A test tool',
            inputSchema: ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            serverName: 'test_server'
        );

        $this->assertEquals('test_tool', $tool->name);
        $this->assertEquals('A test tool', $tool->description);
        $this->assertEquals('test_server', $tool->serverName);
        $this->assertArrayHasKey('type', $tool->inputSchema);
    }

    public function testGetRegistryName(): void
    {
        $tool = new McpTool(
            name: 'my_tool',
            description: 'Tool description',
            inputSchema: [],
            serverName: 'cloudflare'
        );

        $this->assertEquals('mcp_cloudflare_my_tool', $tool->getRegistryName());
    }

    public function testFromArrayWithAllFields(): void
    {
        $data = [
            'name' => 'api_tool',
            'description' => 'An API tool',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'endpoint' => ['type' => 'string'],
                    'method' => ['type' => 'string', 'enum' => ['GET', 'POST']],
                ],
                'required' => ['endpoint'],
            ],
        ];

        $tool = McpTool::fromArray($data, 'api_server');

        $this->assertEquals('api_tool', $tool->name);
        $this->assertEquals('An API tool', $tool->description);
        $this->assertEquals('api_server', $tool->serverName);
        $this->assertEquals('object', $tool->inputSchema['type']);
        $this->assertArrayHasKey('endpoint', $tool->inputSchema['properties']);
    }

    public function testFromArrayWithMissingDescription(): void
    {
        $data = [
            'name' => 'simple_tool',
        ];

        $tool = McpTool::fromArray($data, 'server1');

        $this->assertEquals('simple_tool', $tool->name);
        $this->assertEquals('', $tool->description);
    }

    public function testFromArrayWithMissingInputSchema(): void
    {
        $data = [
            'name' => 'no_schema_tool',
            'description' => 'Tool without schema',
        ];

        $tool = McpTool::fromArray($data, 'server2');

        $this->assertEquals('object', $tool->inputSchema['type']);
        $this->assertEmpty($tool->inputSchema['properties']);
    }

    public function testReadonlyProperties(): void
    {
        $tool = new McpTool(
            name: 'readonly_tool',
            description: 'Testing readonly',
            inputSchema: [],
            serverName: 'test'
        );

        // Verify that properties are accessible (readonly allows reading)
        $this->assertIsString($tool->name);
        $this->assertIsString($tool->description);
        $this->assertIsArray($tool->inputSchema);
        $this->assertIsString($tool->serverName);
    }

    public function testRegistryNameWithSpecialCharacters(): void
    {
        $tool = new McpTool(
            name: 'tool-with-dashes',
            description: 'Tool with dashes in name',
            inputSchema: [],
            serverName: 'server-name'
        );

        // The registry name should preserve the characters
        $this->assertEquals('mcp_server-name_tool-with-dashes', $tool->getRegistryName());
    }
}
