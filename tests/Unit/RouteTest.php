<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use PHPUnit\Framework\TestCase;
use gijsbos\ApiServer\Attributes\GetRoute;
use gijsbos\ApiServer\Attributes\ReturnFilter;
use gijsbos\ApiServer\Attributes\Route;
use gijsbos\ApiServer\Classes\PathVariable;
use gijsbos\ApiServer\Classes\RequestParam;

final class RouteTest extends TestCase
{
    use IsolatesGlobalState;

    private function route(string $path = "/users/{id}/posts/{postId}", string $method = "get") : Route
    {
        return new Route($method, $path);
    }

    // ---- basics ----

    public function testRequestMethodIsUppercased()
    {
        $this->assertSame("GET", $this->route("/x", "get")->getRequestMethod());
        $this->assertSame("PATCH", $this->route("/x", "PaTcH")->getRequestMethod());
    }

    public function testLeadingSlashIsStrippedFromPath()
    {
        $this->assertSame("users/list", $this->route("/users/list")->getPath());
        $this->assertSame("users/list", $this->route("users/list")->getPath());
    }

    public function testTrailingSlashIsPreserved()
    {
        $this->assertSame("users/", $this->route("/users/")->getPath());
    }

    public function testStatusCodeDefaultsTo200AndCanBeChanged()
    {
        $route = $this->route();

        $this->assertSame(200, $route->getStatusCode());

        $route->setStatusCode(204);

        $this->assertSame(204, $route->getStatusCode());
    }

    public function testMethodSpecificAttributesSetMethodAndStatus()
    {
        $route = new GetRoute("/things", 202);

        $this->assertSame("GET", $route->getRequestMethod());
        $this->assertSame("things", $route->getPath());
        $this->assertSame(202, $route->getStatusCode());
    }

    // ---- path variables ----

    public function testPathVariableNames()
    {
        $this->assertSame(["id", "postId"], $this->route()->getPathVariableNames());
        $this->assertSame([], $this->route("/static/path")->getPathVariableNames());
    }

    public function testTypedPlaceholderYieldsJustTheName()
    {
        $this->assertSame(["id"], $this->route("/users/{int:id}")->getPathVariableNames());
    }

    public function testPathPatternMatchesAndCapturesEachSegment()
    {
        $pattern = $this->route()->getPathPattern();

        $this->assertSame(1, preg_match($pattern, "users/5/posts/9", $matches));
        $this->assertSame("5", $matches[1]);
        $this->assertSame("9", $matches[2]);
    }

    public function testPathPatternRejectsOtherPathsAndEmptyPlaceholders()
    {
        $pattern = $this->route("/users/{id}")->getPathPattern();

        $this->assertSame(0, preg_match($pattern, "accounts/5"));
        $this->assertSame(0, preg_match($pattern, "users/"));
        $this->assertSame(0, preg_match($pattern, "users"));
    }

    public function testGetPathFillsPlaceholdersInOrder()
    {
        $this->assertSame("users/5/posts/9", $this->route()->getPath(5, 9));
    }

    public function testGetPathWithoutValuesKeepsPlaceholders()
    {
        $this->assertSame("users/{id}/posts/{postId}", $this->route()->getPath());
    }

    public function testFullPathWithoutServerUsesBaseUrlFromEnvironment()
    {
        $previous = getenv("BASE_URL");
        putenv("BASE_URL=http://example.test/api/");

        try
        {
            $this->assertSame("http://example.test/api/users/5/posts/9", $this->route()->getFullPath(false, 5, 9));
        }
        finally
        {
            $previous === false ? putenv("BASE_URL") : putenv("BASE_URL=$previous");
        }
    }

    public function testPathVariablesStoreAndLookup()
    {
        $route = $this->route();

        $this->assertNull($route->getPathVariables());
        $this->assertNull($route->getPathVariables("id"));

        $route->setPathVariables(["id" => "5", "postId" => "9"]);

        $this->assertSame(["id" => "5", "postId" => "9"], $route->getPathVariables());
        $this->assertSame("5", $route->getPathVariables("id"));
        $this->assertNull($route->getPathVariables("nope"));
    }

    // ---- attributes ----

    public function testAttributesAreExtractedFromClassAndMethodOptions()
    {
        $route = new Route("GET", "/x", 200, ["className" => \TestController::class, "methodName" => "getTest"]);

        $this->assertTrue($route->hasAttribute(ReturnFilter::class));
        $this->assertFalse($route->hasAttribute(GetRoute::class), "Route attributes themselves are excluded");
        $this->assertSame(["name", "id"], $route->getAttributes(ReturnFilter::class)->newInstance()->getFilter());
        $this->assertCount(1, $route->getAttributes());
    }

    public function testGetAttributesByNameReturnsNullWhenAbsent()
    {
        $route = $this->route();

        $this->assertNull($route->getAttributes(ReturnFilter::class));
        $this->assertFalse($route->hasAttribute(ReturnFilter::class));
        $this->assertSame([], $route->getAttributes());
    }

    public function testExtractRouteAttributesAcceptsReflectionMethod()
    {
        $method = new \ReflectionMethod(\TestController::class, "getTest");

        $this->assertEquals(
            Route::extractRouteAttributes(\TestController::class, "getTest"),
            Route::extractRouteAttributes($method)
        );
    }

    // ---- data ----

    public function testDataStartsEmpty()
    {
        $route = $this->route();

        $this->assertFalse($route->hasData());
        $this->assertSame([], $route->getData());
        $this->assertNull($route->getData("k"));
        $this->assertFalse($route->hasData("k"));
    }

    public function testAddDataAndSetData()
    {
        $route = $this->route();

        $route->addData("user", "alice");

        $this->assertTrue($route->hasData());
        $this->assertTrue($route->hasData("user"));
        $this->assertSame("alice", $route->getData("user"));

        $route->setData(["only" => 1]);

        $this->assertNull($route->getData("user"));
        $this->assertSame(["only" => 1], $route->getData());
    }

    public function testHasDataTreatsNullValueAsAbsent()
    {
        $route = $this->route();
        $route->addData("k", null);

        $this->assertFalse($route->hasData("k"));
    }

    // ---- route params, context ----

    public function testRouteParamsCanBeLookedUpByName()
    {
        $route = $this->route();

        $id = PathVariable::createWithoutConstructor("id", $route, "5");
        $name = RequestParam::createWithoutConstructor("name", $route, "x");

        $route->addRouteParam($id);
        $route->addRouteParam($name);

        $this->assertSame([$id, $name], $route->getRouteParams());
        $this->assertSame($name, $route->getRouteParams("name"));
        $this->assertNull($route->getRouteParams("missing"));
    }

    public function testClassMethodAndRequestUriAccessors()
    {
        $route = $this->route();

        $route->setClassName("Foo");
        $route->setMethodName("bar");
        $route->setRequestURI("users/5");

        $this->assertSame("Foo", $route->getClassName());
        $this->assertSame("bar", $route->getMethodName());
        $this->assertSame("Foo::bar", $route->getClassMethod());
        $this->assertSame("users/5", $route->getRequestURI());
    }

    public function testServerIsNullUntilSet()
    {
        $route = $this->route();

        $this->assertNull($route->getServer());

        Server::simulateRequest("GET", "/x");
        $server = new Server();
        $route->setServer($server);

        $this->assertSame($server, $route->getServer());
    }

    public function testReflectionClassMethod()
    {
        $route = $this->route();
        $route->setClassName(\TestController::class);
        $route->setMethodName("getTest");

        $this->assertSame("getTest", $route->getReflectionClassMethod()->getName());
    }

    // ---- executeBeforeRouteMethods ----

    public function testExecuteBeforeRouteMethodsRunsTheAttributeCallbacksWithTheRoute()
    {
        \TestRouteShapesFixture::$beforeRouteCalls = [];

        $route = $this->route("/shapes/before");
        $route->setClassName(\TestRouteShapesFixture::class);
        $route->setMethodName("before");

        $route->executeBeforeRouteMethods();

        $this->assertSame([$route], \TestRouteShapesFixture::$beforeRouteCalls);
    }

    public function testExecuteBeforeRouteMethodsIsANoOpWithoutSuchAttributes()
    {
        \TestRouteShapesFixture::$beforeRouteCalls = [];

        $route = $this->route("/shapes/single");
        $route->setClassName(\TestRouteShapesFixture::class);
        $route->setMethodName("single");

        $this->assertSame([], $route->executeBeforeRouteMethods());
        $this->assertSame([], \TestRouteShapesFixture::$beforeRouteCalls);
    }
}
