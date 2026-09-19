<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use gijsbos\ApiServer\Attributes\GetRoute;
use gijsbos\ApiServer\Attributes\Route;
use gijsbos\ApiServer\Parsers\RouteParser;

final class RouteParserTest extends TestCase
{
    private const ROUTES_FILE = "temp/route-parser-test/routes.php";

    protected function tearDown() : void
    {
        if(is_file(self::ROUTES_FILE))
            unlink(self::ROUTES_FILE);
    }

    public function testParseControllerFile()
    {
        Server::simulateRequest("GET", "/test/200/");
        $this->assertTrue(true);
    }

    // ---- getRoute ----

    public function testGetRouteFromClassAndMethodName()
    {
        $route = RouteParser::getRoute("getTest", \TestController::class);

        $this->assertInstanceOf(GetRoute::class, $route);
        $this->assertSame("test/{id}/", $route->getPath());
        $this->assertSame("GET", $route->getRequestMethod());
    }

    public function testGetRouteFromClassMethodArray()
    {
        $route = RouteParser::getRoute([\TestController::class, "postTest"]);

        $this->assertSame("POST", $route->getRequestMethod());
    }

    public function testGetRouteFromReflectionMethod()
    {
        $route = RouteParser::getRoute(new ReflectionMethod(\TestController::class, "putTest"));

        $this->assertSame("PUT", $route->getRequestMethod());
    }

    public function testGetRouteCarriesTheStatusCodeFromTheAttribute()
    {
        $route = RouteParser::getRoute("created", \TestParamsController::class);

        $this->assertSame(201, $route->getStatusCode());
    }

    public function testGetRouteIsFalseForMethodsWithoutRouteAttribute()
    {
        $this->assertFalse(RouteParser::getRoute("noRoute", \TestRouteShapesFixture::class));
    }

    public function testGetRouteRejectsMultipleRouteAttributesOnOneMethod()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Multiple Route attributes");

        RouteParser::getRoute("double", \TestRouteShapesFixture::class);
    }

    public function testGetRouteRejectsArraysOfTheWrongSize()
    {
        $this->expectException(InvalidArgumentException::class);

        RouteParser::getRoute([\TestController::class]);
    }

    public function testGetRouteNeedsAClassNameWhenGivenAMethodName()
    {
        $this->expectException(InvalidArgumentException::class);

        RouteParser::getRoute("getTest");
    }

    // ---- reflection helpers ----

    public function testRouteControllerClassesAreFoundByInheritance()
    {
        $names = array_map(fn(ReflectionClass $c) => $c->getName(), RouteParser::getRouteControllerClasses());

        $this->assertContains(\TestController::class, $names);
        $this->assertContains(\TestParamsController::class, $names);
        $this->assertNotContains(\TestRouteShapesFixture::class, $names, "not a RouteController");
        $this->assertNotContains(\gijsbos\ApiServer\RouteController::class, $names, "the base class itself is not a controller");
    }

    public function testControllerMethodsAreThePublicOnesCarryingARoute()
    {
        $methods = RouteParser::getRouteControllerClassMethods(new ReflectionClass(\TestController::class));
        $names = array_map(fn(ReflectionMethod $m) => $m->getName(), $methods);

        $this->assertContains("getTest", $names);
        $this->assertContains("testRoute7", $names);
        $this->assertNotContains("setServer", $names, "inherited helper without a Route attribute");
        $this->assertNotContains("__construct", $names);
    }

    public function testAttributeLookupByExactClass()
    {
        $method = new ReflectionMethod(\TestController::class, "getTest");

        $this->assertCount(1, RouteParser::getReflectionMethodAttributeOfClass($method, \gijsbos\ApiServer\Attributes\ReturnFilter::class));
        $this->assertCount(0, RouteParser::getReflectionMethodAttributeOfClass($method, Route::class), "GetRoute is a subclass, not Route itself");
    }

    public function testAttributeLookupBySubclass()
    {
        $method = new ReflectionMethod(\TestController::class, "getTest");

        $found = RouteParser::getReflectionMethodAttributeOfSubclass($method, Route::class);

        $this->assertCount(1, $found);
        $this->assertSame(GetRoute::class, $found[0]->getName());
    }

    public function testAttributeLookupBySubclassAndName()
    {
        $method = new ReflectionMethod(\TestController::class, "getTest");

        $this->assertSame(GetRoute::class, RouteParser::getReflectionMethodAttributeOfSubclass($method, Route::class, GetRoute::class)->getName());
        $this->assertFalse(RouteParser::getReflectionMethodAttributeOfSubclass($method, Route::class, \gijsbos\ApiServer\Attributes\PostRoute::class));
    }

    // ---- parseControllerFiles ----

    private function generateRoutes() : array
    {
        (new RouteParser(self::ROUTES_FILE))->parseControllerFiles();

        return require self::ROUTES_FILE;
    }

    /**
     * Collects every "Class::method" entry in the generated trie.
     */
    private function flatten(array $trie) : array
    {
        $found = [];

        array_walk_recursive($trie, function($value) use (&$found)
        {
            if(is_string($value) && str_contains($value, "::"))
                $found[] = $value;
        });

        return $found;
    }

    public function testGeneratesRouteFileAndCreatesMissingDirectory()
    {
        $dir = dirname(self::ROUTES_FILE);

        if(is_dir($dir))
        {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }

        $routes = $this->generateRoutes();

        $this->assertIsArray($routes);
        $this->assertFileExists(self::ROUTES_FILE);
    }

    public function testRoutesAreGroupedByRequestMethod()
    {
        $routes = $this->generateRoutes();

        $this->assertEqualsCanonicalizing(["GET", "POST", "PUT", "DELETE"], array_keys($routes));
    }

    public function testStaticPathsBecomeNestedKeys()
    {
        $routes = $this->generateRoutes();

        $this->assertSame(["TestController::testRoute7"], $routes["GET"]["foo"]["hi"]);
        $this->assertSame(["TestController::requiresAuthorization"], $routes["GET"]["test"]["authorized"]);
    }

    public function testPlaceholdersAreKeyedByTheirDepthAndTrailingSlashGetsItsOwnBranch()
    {
        $routes = $this->generateRoutes();

        // "foo/{a}" and "foo/{a}/" are distinct routes
        $this->assertSame("TestController::testRoute1", $routes["GET"]["foo"]["{1}"][0]);
        $this->assertSame("TestController::testRoute2", $routes["GET"]["foo"]["{1}"][""][0]);
    }

    public function testEveryPublishedRouteIsRegisteredExactlyOnce()
    {
        $all = $this->flatten($this->generateRoutes());

        $this->assertContains("TestController::getTest", $all);
        $this->assertContains("TestParamsController::intPathVariable", $all);
        $this->assertSame(array_values(array_unique($all)), $all, "no route is registered twice");
    }

    public function testUnpublishedRoutesAreLeftOut()
    {
        $all = $this->flatten($this->generateRoutes());

        $this->assertNotContains("TestController::notPublished", $all);
    }

    public function testMethodsOfNonControllerClassesAreLeftOut()
    {
        $all = $this->flatten($this->generateRoutes());

        $this->assertEmpty(array_filter($all, fn($m) => str_starts_with($m, "TestRouteShapesFixture::")));
    }
}
