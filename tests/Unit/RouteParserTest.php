<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use PHPUnit\Framework\TestCase;

final class RouteParserTest extends TestCase
{
    public function testParseControllerFile()
    {
        Server::simulateRequest("GET", "/test/200/");
        $this->assertTrue(true);
    }
}