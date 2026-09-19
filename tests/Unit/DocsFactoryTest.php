<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use gijsbos\ApiServer\Utils\DocsFactory;

final class DocsFactoryTest extends TestCase
{
    private function controller(array $docs, string $class) : array
    {
        foreach($docs["controllers"] as $controller)
            if($controller["class"] === $class)
                return $controller;

        $this->fail("Controller $class not found in generated docs");
    }

    private function route(array $controller, string $method) : array
    {
        foreach($controller["routes"] as $route)
            if($route["method"] === $method)
                return $route;

        $this->fail("Route $method not found in {$controller["class"]}");
    }

    private function param(array $route, string $name) : array
    {
        foreach($route["parameters"] as $parameter)
            if($parameter["name"] === $name)
                return $parameter;

        $this->fail("Parameter $name not found in route {$route["method"]}");
    }

    public function testDocumentsEveryControllerWithRoutes()
    {
        $docs = (new DocsFactory())->create();

        $classes = array_column($docs["controllers"], "class");

        $this->assertContains(\TestController::class, $classes);
        $this->assertContains(\TestParamsController::class, $classes);
        $this->assertNotContains(\TestRouteShapesFixture::class, $classes);
    }

    public function testRouteBasics()
    {
        $route = $this->route($this->controller((new DocsFactory())->create(), \TestController::class), "getTest");

        $this->assertSame("GET", $route["requestMethod"]);
        $this->assertSame(200, $route["statusCode"]);
        $this->assertSame("test/{id}/", $route["path"]);
        $this->assertSame(["id"], $route["pathVariableNames"]);
    }

    public function testStatusCodeComesFromTheRouteAttribute()
    {
        $route = $this->route($this->controller((new DocsFactory())->create(), \TestParamsController::class), "created");

        $this->assertSame(201, $route["statusCode"]);
    }

    public function testReturnFilterIsIncluded()
    {
        $controller = $this->controller((new DocsFactory())->create(), \TestController::class);

        $this->assertSame(["name", "id"], $this->route($controller, "getTest")["returnFilter"]);
        $this->assertArrayNotHasKey("returnFilter", $this->route($controller, "testRoute1"));
    }

    public function testRoutesWithoutParametersHaveAnEmptyList()
    {
        $route = $this->route($this->controller((new DocsFactory())->create(), \TestController::class), "testRoute1");

        $this->assertSame([], $route["parameters"]);
    }

    public function testPathVariableParameter()
    {
        $route = $this->route($this->controller((new DocsFactory())->create(), \TestController::class), "getTest");
        $id = $this->param($route, "id");

        $this->assertSame("pathVariable", $id["kind"]);
        $this->assertSame("string", $id["type"]);
        $this->assertSame(4, $id["max"]);
    }

    public function testRequestParamParameterCarriesItsDeclaredOptions()
    {
        $route = $this->route($this->controller((new DocsFactory())->create(), \TestController::class), "getTest");
        $name = $this->param($route, "name");

        $this->assertSame("requestParam", $name["kind"]);
        $this->assertSame("string", $name["type"]);
        $this->assertSame(10, $name["max"]);
        $this->assertSame('/^[\w]+$/', $name["pattern"]);
        $this->assertSame("john", $name["default"]);
    }

    public function testRequestHeaderParameter()
    {
        $route = $this->route($this->controller((new DocsFactory())->create(), \TestParamsController::class), "header");
        $token = $this->param($route, "token");

        $this->assertSame("requestHeader", $token["kind"]);
        $this->assertSame("none", $token["default"]);
    }

    public function testOptRequestParamIsDocumentedAsNotRequired()
    {
        $route = $this->route($this->controller((new DocsFactory())->create(), \TestParamsController::class), "optionalParam");

        $this->assertFalse($this->param($route, "note")["required"]);
    }

    public function testRouteAndControllerLevelDocsAttributesAreExported()
    {
        $controller = $this->controller((new DocsFactory())->create(), \TestParamsController::class);
        $route = $this->route($controller, "intPathVariable");

        $this->assertSame("Params", $controller["name"]);
        $this->assertSame("Parameter handling fixtures", $controller["description"]);
        $this->assertSame("intPathVariable", $route["name"]);
        $this->assertSame("Integer path variable", $route["description"]);
        $this->assertSame(["id" => 5], $route["exampleResponse"]);
    }

    public function testCallbackCanSkipRoutes()
    {
        $docs = (new DocsFactory())->create(fn(array $route) => $route["method"] !== "getTest" ? null : false);

        $controller = $this->controller($docs, \TestController::class);

        $methods = array_column($controller["routes"], "method");

        $this->assertNotContains("getTest", $methods);
        $this->assertContains("postTest", $methods);
    }

    public function testCallbackReturningAnArrayReplacesTheRouteData()
    {
        $docs = (new DocsFactory())->create(function(array $route)
        {
            return $route["method"] === "getTest" ? ["method" => "getTest", "replaced" => true] : null;
        });

        $route = $this->route($this->controller($docs, \TestController::class), "getTest");

        $this->assertSame(["method" => "getTest", "replaced" => true], $route);
    }

    public function testCallbackReceivesRouteDataMethodAndClassReflection()
    {
        $received = null;

        (new DocsFactory())->create(function($routeData, $method, $class) use (&$received)
        {
            if($routeData["method"] === "getTest" && $class->getName() === \TestController::class)
                $received = [$routeData, $method, $class];
        });

        $this->assertNotNull($received);
        $this->assertSame("test/{id}/", $received[0]["path"]);
        $this->assertInstanceOf(ReflectionMethod::class, $received[1]);
        $this->assertSame("getTest", $received[1]->getName());
        $this->assertInstanceOf(ReflectionClass::class, $received[2]);
    }

    public function testControllersWhoseRoutesAreAllSkippedAreOmitted()
    {
        $docs = (new DocsFactory())->create(fn(array $route) => false);

        $this->assertSame([], $docs["controllers"]);
    }
}
