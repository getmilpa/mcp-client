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
use Milpa\McpClient\Contracts\McpCapabilities;

class McpCapabilitiesTest extends TestCase
{
    public function testDefaultConstructor(): void
    {
        $capabilities = new McpCapabilities();

        $this->assertFalse($capabilities->supportsTools);
        $this->assertFalse($capabilities->supportsResources);
        $this->assertFalse($capabilities->supportsPrompts);
        $this->assertFalse($capabilities->supportsLogging);
        $this->assertNull($capabilities->protocolVersion);
    }

    public function testConstructorWithAllTrue(): void
    {
        $capabilities = new McpCapabilities(
            supportsTools: true,
            supportsResources: true,
            supportsPrompts: true,
            supportsLogging: true,
            protocolVersion: '2024-11-05'
        );

        $this->assertTrue($capabilities->supportsTools);
        $this->assertTrue($capabilities->supportsResources);
        $this->assertTrue($capabilities->supportsPrompts);
        $this->assertTrue($capabilities->supportsLogging);
        $this->assertEquals('2024-11-05', $capabilities->protocolVersion);
    }

    public function testFromArrayWithAllCapabilities(): void
    {
        $data = [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => ['listChanged' => true],
                'resources' => ['subscribe' => true],
                'prompts' => ['listChanged' => false],
                'logging' => [],
            ],
        ];

        $capabilities = McpCapabilities::fromArray($data);

        $this->assertTrue($capabilities->supportsTools);
        $this->assertTrue($capabilities->supportsResources);
        $this->assertTrue($capabilities->supportsPrompts);
        $this->assertTrue($capabilities->supportsLogging);
        $this->assertEquals('2024-11-05', $capabilities->protocolVersion);
    }

    public function testFromArrayWithPartialCapabilities(): void
    {
        $data = [
            'protocolVersion' => '2024-10-01',
            'capabilities' => [
                'tools' => [],
            ],
        ];

        $capabilities = McpCapabilities::fromArray($data);

        $this->assertTrue($capabilities->supportsTools);
        $this->assertFalse($capabilities->supportsResources);
        $this->assertFalse($capabilities->supportsPrompts);
        $this->assertFalse($capabilities->supportsLogging);
        $this->assertEquals('2024-10-01', $capabilities->protocolVersion);
    }

    public function testFromArrayWithEmptyCapabilities(): void
    {
        $data = [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ];

        $capabilities = McpCapabilities::fromArray($data);

        $this->assertFalse($capabilities->supportsTools);
        $this->assertFalse($capabilities->supportsResources);
        $this->assertFalse($capabilities->supportsPrompts);
        $this->assertFalse($capabilities->supportsLogging);
    }

    public function testFromArrayWithMissingCapabilities(): void
    {
        $data = [
            'protocolVersion' => '2024-11-05',
        ];

        $capabilities = McpCapabilities::fromArray($data);

        $this->assertFalse($capabilities->supportsTools);
        $this->assertFalse($capabilities->supportsResources);
        $this->assertFalse($capabilities->supportsPrompts);
        $this->assertFalse($capabilities->supportsLogging);
    }

    public function testFromArrayWithMissingProtocolVersion(): void
    {
        $data = [
            'capabilities' => [
                'tools' => [],
                'resources' => [],
            ],
        ];

        $capabilities = McpCapabilities::fromArray($data);

        $this->assertNull($capabilities->protocolVersion);
        $this->assertTrue($capabilities->supportsTools);
        $this->assertTrue($capabilities->supportsResources);
    }

    public function testFromArrayWithEmptyData(): void
    {
        $capabilities = McpCapabilities::fromArray([]);

        $this->assertFalse($capabilities->supportsTools);
        $this->assertFalse($capabilities->supportsResources);
        $this->assertFalse($capabilities->supportsPrompts);
        $this->assertFalse($capabilities->supportsLogging);
        $this->assertNull($capabilities->protocolVersion);
    }

    public function testReadonlyProperties(): void
    {
        $capabilities = new McpCapabilities(
            supportsTools: true,
            protocolVersion: '1.0.0'
        );

        // Verify readonly properties are accessible
        $this->assertIsBool($capabilities->supportsTools);
        $this->assertIsBool($capabilities->supportsResources);
        $this->assertIsBool($capabilities->supportsPrompts);
        $this->assertIsBool($capabilities->supportsLogging);
    }
}
