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
use Milpa\McpClient\Contracts\McpResource;

class McpResourceTest extends TestCase
{
    public function testConstructor(): void
    {
        $resource = new McpResource(
            uri: 'file:///path/to/file.txt',
            name: 'file.txt',
            description: 'A text file',
            mimeType: 'text/plain',
            serverName: 'filesystem'
        );

        $this->assertEquals('file:///path/to/file.txt', $resource->uri);
        $this->assertEquals('file.txt', $resource->name);
        $this->assertEquals('A text file', $resource->description);
        $this->assertEquals('text/plain', $resource->mimeType);
        $this->assertEquals('filesystem', $resource->serverName);
    }

    public function testConstructorWithNullOptionalFields(): void
    {
        $resource = new McpResource(
            uri: 'http://example.com/data',
            name: 'data',
            description: null,
            mimeType: null,
            serverName: 'api'
        );

        $this->assertNull($resource->description);
        $this->assertNull($resource->mimeType);
    }

    public function testFromArrayWithAllFields(): void
    {
        $data = [
            'uri' => 'sqlite:///db/main.db',
            'name' => 'main database',
            'description' => 'The main SQLite database',
            'mimeType' => 'application/x-sqlite3',
        ];

        $resource = McpResource::fromArray($data, 'sqlite_server');

        $this->assertEquals('sqlite:///db/main.db', $resource->uri);
        $this->assertEquals('main database', $resource->name);
        $this->assertEquals('The main SQLite database', $resource->description);
        $this->assertEquals('application/x-sqlite3', $resource->mimeType);
        $this->assertEquals('sqlite_server', $resource->serverName);
    }

    public function testFromArrayWithMissingOptionalFields(): void
    {
        $data = [
            'uri' => 'custom://resource/id',
            'name' => 'custom resource',
        ];

        $resource = McpResource::fromArray($data, 'custom_server');

        $this->assertEquals('custom://resource/id', $resource->uri);
        $this->assertEquals('custom resource', $resource->name);
        $this->assertNull($resource->description);
        $this->assertNull($resource->mimeType);
    }

    public function testFromArrayWithOnlyDescription(): void
    {
        $data = [
            'uri' => 'test://resource',
            'name' => 'test',
            'description' => 'Has description but no mimeType',
        ];

        $resource = McpResource::fromArray($data, 'test_server');

        $this->assertEquals('Has description but no mimeType', $resource->description);
        $this->assertNull($resource->mimeType);
    }

    public function testFromArrayWithOnlyMimeType(): void
    {
        $data = [
            'uri' => 'blob://data',
            'name' => 'binary data',
            'mimeType' => 'application/octet-stream',
        ];

        $resource = McpResource::fromArray($data, 'blob_server');

        $this->assertNull($resource->description);
        $this->assertEquals('application/octet-stream', $resource->mimeType);
    }

    public function testReadonlyProperties(): void
    {
        $resource = new McpResource(
            uri: 'test://uri',
            name: 'test',
            description: 'desc',
            mimeType: 'text/plain',
            serverName: 'server'
        );

        // Verify readonly properties are accessible
        $this->assertIsString($resource->uri);
        $this->assertIsString($resource->name);
        $this->assertIsString($resource->serverName);
    }
}
