<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use gijsbos\ApiServer\Utils\RouteParser;
use PHPUnit\Framework\TestCase;

final class ServerTest extends TestCase
{
    public static function setupBeforeClass() : void
    {
        (new RouteParser(Server::$DEFAULT_ROUTES_FILE))
        ->parseControllerFiles();
    }

    protected function setUp() : void
    {
        
    }

    public function testRoute1()
    {
        Server::simulateRequest("GET", "/foo/a");

        $server = new Server();

        $response = $server->listen(false);

        $this->assertTrue($response["result"] == "testRoute1", implode(", ", $response));
    }

    public function testRoute2()
    {
        Server::simulateRequest("GET", "/foo/a/");

        $server = new Server();

        $response = $server->listen(false);

        $this->assertTrue($response["result"] == "testRoute2", implode(", ", $response));
    }

    public function testRoute3()
    {
        Server::simulateRequest("GET", "/foo/a/bar");

        $server = new Server();

        $response = $server->listen(false);

        $this->assertTrue($response["result"] == "testRoute3", implode(", ", $response));
    }

    public function testRoute4()
    {
        Server::simulateRequest("GET", "/foo/a/bar/");

        $server = new Server();

        $response = $server->listen(false);

        $this->assertTrue($response["result"] == "testRoute4", implode(", ", $response));
    }

    public function testRoute5()
    {
        Server::simulateRequest("GET", "/foo/a/bar/b/");

        $server = new Server();

        $response = $server->listen(false);

        $this->assertTrue($response["result"] == "testRoute5", implode(", ", $response));
    }

    public function testRoute6()
    {
        Server::simulateRequest("GET", "/foo/a/baz");

        $server = new Server();

        $response = $server->listen(false);

        $this->assertTrue($response["result"] == "testRoute6", implode(", ", $response));
    }

    public function testRoute7()
    {
        Server::simulateRequest("GET", "/foo/hi");

        $server = new Server();

        $response = $server->listen(false);

        $this->assertTrue($response["result"] == "testRoute7", implode(", ", $response));
    }

    public function testRoute8()
    {
        Server::simulateRequest("GET", "/account/account_2rN3rW4xW0No/");

        $server = new Server();

        $response = $server->listen(false);

        $this->assertTrue($response["result"] == "testRoute8", implode(", ", $response));
    }
}