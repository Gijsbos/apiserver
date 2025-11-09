<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use gijsbos\ApiServer\Utils\RouteParser;

use PHPUnit\Framework\TestCase;

final class RouteParserTest extends TestCase
{
    public function testParseControllerFile()
    {
        $routeParser = new RouteParser();

        $routeParser->parseControllerFiles();

        Server::simulateRequest("GET", "/appelsap/hi/");

        $server = new Server();

        $server->listen();
    }
}