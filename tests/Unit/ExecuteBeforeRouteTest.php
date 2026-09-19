<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use LogicException;
use PHPUnit\Framework\TestCase;
use gijsbos\ApiServer\Attributes\ExecuteBeforeRoute;
use gijsbos\ApiServer\Attributes\Route;

final class ExecuteBeforeRouteTest extends TestCase
{
    protected function setUp() : void
    {
        \TestRouteShapesFixture::$beforeRouteCalls = [];
    }

    private function routeFor(string $method) : Route
    {
        $route = new Route("GET", "/shapes/x");
        $route->setClassName(\TestRouteShapesFixture::class);
        $route->setMethodName($method);

        return $route;
    }

    public function testCallbackReceivesTheRoute()
    {
        $received = null;
        $route = $this->routeFor("single");

        (new ExecuteBeforeRoute(function(Route $r) use (&$received) { $received = $r; }))->execute($route);

        $this->assertSame($route, $received);
    }

    public function testGetCallbackReturnsWhatWasGiven()
    {
        $callback = fn() => null;

        $this->assertSame($callback, (new ExecuteBeforeRoute($callback))->getCallback());
        $this->assertNull((new ExecuteBeforeRoute())->getCallback());
    }

    public function testExecutingWithoutACallbackThrows()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("TestRouteShapesFixture::single has no callback set");

        (new ExecuteBeforeRoute())->execute($this->routeFor("single"));
    }

    public function testExecutingWithACallbackThatIsNotCallableThrows()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("not callable");

        (new ExecuteBeforeRoute("No\\Such\\Callback::method"))->execute($this->routeFor("single"));
    }

    public function testRouteRunsADirectExecuteBeforeRouteAttribute()
    {
        $route = $this->routeFor("directBefore");

        $route->executeBeforeRouteMethods();

        $this->assertSame([$route], \TestRouteShapesFixture::$beforeRouteCalls);
    }

    public function testRouteThrowsForABareAttributeWithoutCallback()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("has no callback set");

        $this->routeFor("bareBefore")->executeBeforeRouteMethods();
    }

    public function testRouteThrowsForAnAttributeWhoseCallbackIsNotCallable()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("not callable");

        $this->routeFor("notCallableBefore")->executeBeforeRouteMethods();
    }

    public function testSubclassesStillRun()
    {
        $route = $this->routeFor("before");

        $route->executeBeforeRouteMethods();

        $this->assertSame([$route], \TestRouteShapesFixture::$beforeRouteCalls);
    }
}
