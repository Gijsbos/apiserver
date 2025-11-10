<?php
declare(strict_types=1);

use gijsbos\ApiServer\Classes\DeleteRoute;
use gijsbos\ApiServer\Classes\GetRoute;
use gijsbos\ApiServer\Classes\PathVariable;
use gijsbos\ApiServer\Classes\PostRoute;
use gijsbos\ApiServer\Classes\PutRoute;
use gijsbos\ApiServer\Classes\RequestParam;
use gijsbos\ApiServer\Classes\ReturnFilter;
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
        RequestParam|string $name = new RequestParam(["min" => 0, "max" => 10, "pattern" => "/^[a-z]+$/", "required" => false, "default" => "john"]),
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
        RequestParam|string $name = new RequestParam(["pattern" => "/^[a-z]+$/", "required" => true]),
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
        RequestParam|string $name = new RequestParam(["pattern" => "/^[a-z]+$/", "required" => false]),
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
        RequestParam|string $name = new RequestParam(["pattern" => "/^[a-z]+$/", "required" => false]),
    )
    {
        return [
            "id" => $id,
            "name" => $name,
        ];
    }
}