<?php

namespace Streeboga\WeeekMcp;

use Pronskiy\Mcp\Server as McpServer;

class Server
{
    private McpServer $server;

    public function __construct()
    {
        $this->server = new McpServer('weeek-mcp-server');
        $this->registerTools();
    }

    private function registerTools(): void
    {
        $this->server
            ->tool(
                'get-current-time',
                'Returns current server time',
                fn() => "Current server time is: " . date('Y-m-d H:i:s')
            )
            ->tool(
                'echo-message',
                'Echoes back the provided message',
                fn(string $message) => "You said: {$message}"
            )
            ->tool(
                'calculate',
                'Performs basic arithmetic operations',
                function(float $num1, float $num2, string $operation = '+') {
                    $result = match ($operation) {
                        '+' => $num1 + $num2,
                        '-' => $num1 - $num2,
                        '*' => $num1 * $num2,
                        '/' => $num2 != 0 ? $num1 / $num2 : 'Division by zero!',
                        default => 'Unknown operation'
                    };
                    return "Result of {$num1} {$operation} {$num2} = {$result}";
                }
            );
    }

    public function run(): void
    {
        $this->server->run();
    }
} 