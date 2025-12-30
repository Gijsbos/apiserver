<?php
declare(strict_types=1);

use gijsbos\ApiServer\Attributes\DeleteRoute;
use gijsbos\ApiServer\Attributes\GetRoute;
use gijsbos\ApiServer\Classes\PathVariable;
use gijsbos\ApiServer\Attributes\PostRoute;
use gijsbos\ApiServer\Attributes\Published;
use gijsbos\ApiServer\Attributes\PutRoute;
use gijsbos\ApiServer\Classes\RequestHeader;
use gijsbos\ApiServer\Classes\RequestParam;
use gijsbos\ApiServer\Attributes\RequiresAuthorization;
use gijsbos\ApiServer\Attributes\ReturnFilter;
use gijsbos\ApiServer\RouteController;

/**
 * TestController
 */
class TestController extends RouteController
{
    /**
     * getTest
     */
    #[GetRoute('/test/{id}/')]
    #[ReturnFilter(['name','id'])]
    public function getTest(
        PathVariable|string $id = new PathVariable(["min" => 0, "max" => 4, "required" => false]),
        RequestParam|string $name = new RequestParam(["min" => 0, "max" => 10, "pattern" => "/^[\w]+$/", "required" => false, "default" => "john"]),
    )
    {
        return [
            "id" => "<$id>",
            "name" => $name,
        ];
    }

    /**
     * postTest
     */
    #[PostRoute('/test/{id}/')]
    #[ReturnFilter(['name','id'])]
    public function postTest(
        PathVariable|int $id = new PathVariable(["min" => 0, "max" => 4, "required" => false]),
        RequestParam|string $name = new RequestParam(["pattern" => "/^[\w]+$/", "required" => true]),
    )
    {
        return [
            "id" => $id,
            "name" => $name,
        ];
    }

    /**
     * putTest
     */
    #[PutRoute('/test/{id}/')]
    #[ReturnFilter(['name','id'])]
    public function putTest(
        PathVariable|int $id = new PathVariable(["min" => 0, "max" => 4, "required" => false]),
        RequestParam|string $name = new RequestParam(["pattern" => "/^[\w]+$/", "required" => false]),
    )
    {
        return [
            "id" => $id,
            "name" => $name,
        ];
    }

    /**
     * deleteTest
     */
    #[DeleteRoute('/test/{id}/')]
    #[ReturnFilter(['name','id'])]
    public function deleteTest(
        PathVariable|int $id = new PathVariable(["min" => 0, "max" => 4, "required" => false]),
        RequestParam|string $name = new RequestParam(["pattern" => "/^[\w]+$/", "required" => false]),
    )
    {
        return [
            "id" => $id,
            "name" => $name,
        ];
    }

    /**
     * requiresAuthorization
     */
    #[GetRoute('/test/authorized')]
    #[ReturnFilter(['token'])]
    #[RequiresAuthorization()]
    public function requiresAuthorization(
        RequestHeader|string $authorization = new RequestHeader(),
    )
    {
        return [
            "token" => $authorization,
        ];
    }

    /**
     * notPublished
     */
    #[Published(false)]
    #[GetRoute('/test/not-published')]
    #[RequiresAuthorization()]
    public function notPublished(
        RequestHeader|string $authorization = new RequestHeader(),
    )
    {
        return [
            "result" => "ok",
        ];
    }

    /**
     * testRoute1
     */
    #[GetRoute('/foo/{a}')]
    public function testRoute1()
    {
        return ["result" => "testRoute1"];
    }

    /**
     * testRoute2
     */
    #[GetRoute('/foo/{a}/')]
    public function testRoute2()
    {
        return ["result" => "testRoute2"];
    }

    /**
     * testRoute3
     */
    #[GetRoute('/foo/{a}/bar')]
    public function testRoute3()
    {
        return ["result" => "testRoute3"];
    }

    /**
     * testRoute4
     */
    #[GetRoute('/foo/{a}/bar/')]
    public function testRoute4()
    {
        return ["result" => "testRoute4"];
    }

    /**
     * testRoute5
     */
    #[GetRoute('/foo/{a}/bar/{b}/')]
    public function testRoute5()
    {
        return ["result" => "testRoute5"];
    }

    /**
     * testRoute6
     */
    #[GetRoute('/foo/{a}/baz')]
    public function testRoute6()
    {
        return ["result" => "testRoute6"];
    }

    /**
     * testRoute7
     */
    #[GetRoute('/foo/hi')]
    public function testRoute7()
    {
        return ["result" => "testRoute7"];
    }

    /**
     * testRoute8A
     */
    #[GetRoute('/account/{accountId}/api-request/insights')]
    public function testRoute8A()
    {
        return ["result" => "testRoute8"];
    }

    /**
     * testRoute8
     */
    #[GetRoute('/account/{accountId}/')]
    public function testRoute8()
    {
        return ["result" => "testRoute8"];
    }
}