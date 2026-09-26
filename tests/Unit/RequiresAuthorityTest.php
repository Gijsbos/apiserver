<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use gijsbos\ApiServer\Attributes\ExecuteBeforeRoute;
use gijsbos\ApiServer\Attributes\RequiresAuthority;
use gijsbos\ApiServer\Attributes\Route;
use gijsbos\Http\Exceptions\ForbiddenException;

final class RequiresAuthorityTest extends TestCase
{
    use IsolatesGlobalState;

    protected function setUp() : void
    {
        \TestAllowAuthorityCheck::$calls = [];
    }

    private function route() : Route
    {
        return new Route("GET", "/x");
    }

    public function testIsABeforeRouteAttribute()
    {
        $attribute = new RequiresAuthority(\TestAllowAuthorityCheck::class, ["admin"]);

        $this->assertInstanceOf(ExecuteBeforeRoute::class, $attribute);
        $this->assertIsCallable($attribute->getCallback());
    }

    public function testCheckClassNameIsInstantiatedAndGivenRouteAndAuthority()
    {
        $route = $this->route();

        (new RequiresAuthority(\TestAllowAuthorityCheck::class, ["admin", "write"]))->execute($route);

        $this->assertCount(1, \TestAllowAuthorityCheck::$calls);
        $this->assertSame($route, \TestAllowAuthorityCheck::$calls[0]["route"]);
        $this->assertSame(["admin", "write"], \TestAllowAuthorityCheck::$calls[0]["authority"]);
    }

    public function testCheckCanStashDataOnTheRoute()
    {
        $route = $this->route();

        (new RequiresAuthority(\TestAllowAuthorityCheck::class, ["admin"]))->execute($route);

        $this->assertSame(["admin"], $route->getData("authority"));
    }

    public function testACheckRunsEveryTimeTheAttributeIsExecuted()
    {
        $attribute = new RequiresAuthority(\TestAllowAuthorityCheck::class, ["admin"]);

        $attribute->execute($this->route());
        $attribute->execute($this->route());

        $this->assertCount(2, \TestAllowAuthorityCheck::$calls);
    }

    public function testDenyingCheckExceptionPropagatesToTheCaller()
    {
        $attribute = new RequiresAuthority(\TestDenyAuthorityCheck::class, ["admin"]);

        $e = $this->assertHttpError(ForbiddenException::class, "insufficientAuthority", fn() => $attribute->execute($this->route()));

        $this->assertSame(403, $e->getStatusCode());
        $this->assertStringContainsString("admin", $e->getErrorDescription());
    }

    public function testStaticFactoryGivenAsArrayCallable()
    {
        (new RequiresAuthority([\TestAuthorityCheckFactory::class, "make"], ["admin"]))->execute($this->route());

        $this->assertCount(1, \TestAllowAuthorityCheck::$calls);
    }

    public function testStaticFactoryGivenAsStringCallable()
    {
        (new RequiresAuthority("TestAuthorityCheckFactory::make", ["admin"]))->execute($this->route());

        $this->assertCount(1, \TestAllowAuthorityCheck::$calls);
    }

    public function testClosureFactoryWorksWhenConstructedAtRuntime()
    {
        $attribute = new RequiresAuthority(fn() => new \TestAllowAuthorityCheck(), ["admin"]);

        $attribute->execute($this->route());

        $this->assertCount(1, \TestAllowAuthorityCheck::$calls);
    }

    public function testFactoryMustReturnAnAuthorityCheck()
    {
        $attribute = new RequiresAuthority([\TestAuthorityCheckFactory::class, "makeInvalid"], ["admin"]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("must return an object implementing RouteAuthorityVerifierInterface");

        $attribute->execute($this->route());
    }

    public function testUnknownClassIsRejectedWhenExecuted()
    {
        $attribute = new RequiresAuthority("No\\Such\\Check", ["admin"]);

        $this->expectException(InvalidArgumentException::class);

        $attribute->execute($this->route());
    }

    public function testClassNotImplementingTheInterfaceIsRejectedWhenExecuted()
    {
        $attribute = new RequiresAuthority(\stdClass::class, ["admin"]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("expects a class name implementing RouteAuthorityVerifierInterface");

        $attribute->execute($this->route());
    }

    public function testMisconfigurationIsOnlyDetectedAtExecutionNotConstruction()
    {
        // Construction must stay cheap: attribute instantiation happens during route matching
        new RequiresAuthority("No\\Such\\Check", ["admin"]);

        $this->addToAssertionCount(1);
    }

    public function testRunsAsPartOfARouteMethodsBeforeRouteAttributes()
    {
        $route = new Route("GET", "/params/authority-allow");
        $route->setClassName(\TestParamsController::class);
        $route->setMethodName("authorityAllow");

        $route->executeBeforeRouteMethods();

        $this->assertCount(1, \TestAllowAuthorityCheck::$calls);
        $this->assertSame(["admin", "write"], $route->getData("authority"));
    }

    public function testDenyingCheckAbortsRouteExecution()
    {
        $route = new Route("GET", "/params/authority-deny");
        $route->setClassName(\TestParamsController::class);
        $route->setMethodName("authorityDeny");

        $this->assertHttpError(ForbiddenException::class, "insufficientAuthority", fn() => $route->executeBeforeRouteMethods());
    }
}
