<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use gijsbos\ApiServer\Utils\RouteParser;
use gijsbos\Http\Http\HTTPRequest;

/**
 * TestControllerRouteTest
 *  The name cannot be the same as the auto generated TestControllerTest class, hence TestControllerRouteTest
 */
class TestControllerRouteTest extends TestCase
{
    public static function setupBeforeClass() : void
    {

    }

    public function testGetTest() : void
    {
        # Path Variables
        $id = 1;

        # Request Params
        $name = "Foo";

        # Send Request
        $response = HTTPRequest::get([
            "uri" => RouteParser::getRoute(['TestController', 'getTest'])->getFullPath(false, $id),
            "data" => [
                "name" => $name,
            ]
        ]);

        # Test Result
        $this->assertTrue($response->isSuccessful(), $response->getErrorString());
    }

    public function testPostTest() : void
    {
        # Path Variables
        $id = 1;

        # Request Params
        $name = "Foo";

        # Send Request
        $response = HTTPRequest::post([
            "uri" => RouteParser::getRoute(['TestController', 'postTest'])->getFullPath(false, $id),
            "data" => [
                "name" => $name,
            ]
        ]);

        # Test Result
        $this->assertTrue($response->isSuccessful(), $response->getErrorString());
    }

    public function testPutTest() : void
    {
        # Path Variables
        $id = 1;

        # Request Params
        $name = "Foo";

        # Send Request
        $response = HTTPRequest::put([
            "uri" => RouteParser::getRoute(['TestController', 'putTest'])->getFullPath(false, $id),
            "data" => [
                "name" => $name,
            ]
        ]);

        # Test Result
        $this->assertTrue($response->isSuccessful(), $response->getErrorString());
    }

    public function testDeleteTest() : void
    {
        # Path Variables
        $id = 1;

        # Request Params
        $name = "Foo";

        # Send Request
        $response = HTTPRequest::delete([
            "uri" => RouteParser::getRoute(['TestController', 'deleteTest'])->getFullPath(false, $id),
            "data" => [
                "name" => $name,
            ]
        ]);

        # Test Result
        $this->assertTrue($response->isSuccessful(), $response->getErrorString());
    }

    public function testRequiresAuthorization() : void
    {
        # Header Params
        $token = "secret";

        # Send Request
        $response = HTTPRequest::get([
            "uri" => RouteParser::getRoute(['TestController', 'requiresAuthorization'])->getFullPath(false),
            "headers" => [
                "Authorization" => "Bearer $token",
            ]
        ]);

        # Test Result
        $this->assertFalse($response->isSuccessful(), $response->getErrorString());
    }
}