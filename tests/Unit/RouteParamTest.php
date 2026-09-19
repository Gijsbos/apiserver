<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use PHPUnit\Framework\TestCase;
use gijsbos\ApiServer\Attributes\Route;
use gijsbos\ApiServer\Classes\OptRequestParam;
use gijsbos\ApiServer\Classes\PathVariable;
use gijsbos\ApiServer\Classes\RequestHeader;
use gijsbos\ApiServer\Classes\RequestParam;
use gijsbos\ApiServer\Classes\RouteParam;

final class RouteParamTest extends TestCase
{
    use IsolatesGlobalState;

    // ---- RouteParam ----

    public function testOptionsArePromotedToProperties()
    {
        $param = new RouteParam([
            "type" => "int",
            "customType" => "email",
            "min" => 1,
            "max" => 9,
            "pattern" => "/x/",
            "values" => ["a", "b"],
            "required" => false,
            "default" => "d",
            "description" => "desc",
        ]);

        $this->assertSame("int", $param->type);
        $this->assertSame("email", $param->customType);
        $this->assertSame(1, $param->getMin());
        $this->assertSame(9, $param->getMax());
        $this->assertSame("/x/", $param->getPattern());
        $this->assertSame(["a", "b"], $param->getValues());
        $this->assertFalse($param->isRequired());
        $this->assertSame("d", $param->getDefault());
        $this->assertSame("desc", $param->getDescription());
    }

    public function testPromotedOptionsAreRemovedFromRemainingOpts()
    {
        $param = new RouteParam(["min" => 1, "custom" => "kept"]);

        $this->assertSame(["custom" => "kept"], $param->getOpts());
    }

    public function testNamedArgumentsWinOverOptions()
    {
        $param = new RouteParam(["min" => 1, "required" => false], min: 5, required: true);

        $this->assertSame(5, $param->getMin());
        $this->assertTrue($param->isRequired());
    }

    public function testRequiredDefaultsToTrue()
    {
        $this->assertTrue((new RouteParam())->isRequired());
        $this->assertTrue((new RouteParam(["min" => 1]))->isRequired());
    }

    public function testDescriptionFallsBackToDocs()
    {
        $this->assertSame("from docs arg", (new RouteParam([], docs: "from docs arg"))->getDescription());
        $this->assertSame("from docs opt", (new RouteParam(["docs" => "from docs opt"]))->getDescription());
        $this->assertSame("wins", (new RouteParam(["description" => "wins", "docs" => "loses"]))->getDescription());
    }

    public function testEverythingElseDefaultsToNull()
    {
        $param = new RouteParam();

        $this->assertNull($param->type);
        $this->assertNull($param->customType);
        $this->assertNull($param->getMin());
        $this->assertNull($param->getMax());
        $this->assertNull($param->getPattern());
        $this->assertNull($param->getValues());
        $this->assertNull($param->getDefault());
        $this->assertNull($param->getDescription());
        $this->assertSame("", $param->getName());
        $this->assertNull($param->getRoute());
        $this->assertNull($param->getValue());
    }

    public function testCreateWithoutConstructorBindsNameRouteAndValue()
    {
        $route = new Route("GET", "/x");

        $param = RouteParam::createWithoutConstructor("id", $route, "42", "int");

        $this->assertSame("id", $param->getName());
        $this->assertSame($route, $param->getRoute());
        $this->assertSame("42", $param->getValue());
        $this->assertSame("int", $param->type);
    }

    public function testCreateFromObjectKeepsOptionsFromTheDefaultObject()
    {
        $route = new Route("GET", "/x");
        $declared = new PathVariable(["min" => 1, "max" => 4]);

        $bound = RouteParam::createWithoutConstructorFromObject($declared, "id", $route, "3", "int");

        $this->assertSame($declared, $bound);
        $this->assertSame("id", $bound->getName());
        $this->assertSame("3", $bound->getValue());
        $this->assertSame(1, $bound->getMin());
        $this->assertSame(4, $bound->getMax());
    }

    // ---- OptRequestParam ----

    public function testOptRequestParamIsNotRequiredByDefault()
    {
        $this->assertFalse((new OptRequestParam())->isRequired());
        $this->assertFalse((new OptRequestParam(["min" => 1]))->isRequired());
    }

    public function testOptRequestParamCanStillBeMadeRequiredExplicitly()
    {
        $this->assertTrue((new OptRequestParam(["required" => true]))->isRequired());
    }

    public function testOptRequestParamPassesOtherOptionsThrough()
    {
        $param = new OptRequestParam(["default" => "x", "max" => 3]);

        $this->assertSame("x", $param->getDefault());
        $this->assertSame(3, $param->getMax());
    }

    public function testParamClassHierarchy()
    {
        $this->assertInstanceOf(RouteParam::class, new PathVariable());
        $this->assertInstanceOf(RouteParam::class, new RequestHeader());
        $this->assertInstanceOf(RouteParam::class, new RequestParam());
        $this->assertInstanceOf(RequestParam::class, new OptRequestParam());
    }

    // ---- RequestHeader::getHeader ----

    public function testHeaderNamesAreNormalizedToServerKeys()
    {
        $_SERVER["HTTP_X_CUSTOM_HEADER"] = "v";

        $this->assertSame("v", RequestHeader::getHeader("X-Custom-Header"));
        $this->assertSame("v", RequestHeader::getHeader("x-custom-header"));
        $this->assertSame("v", RequestHeader::getHeader("HTTP_X_CUSTOM_HEADER"));
    }

    public function testMissingHeaderIsNull()
    {
        $this->assertNull(RequestHeader::getHeader("X-Not-There"));
    }

    public function testContentHeadersAreReadWithoutHttpPrefix()
    {
        $_SERVER["CONTENT_TYPE"] = "application/json";
        $_SERVER["CONTENT_LENGTH"] = "12";

        $this->assertSame("application/json", RequestHeader::getHeader("content-type"));
        $this->assertSame("12", RequestHeader::getHeader("Content-Length"));
    }

    public function testRedirectPrefixedHeaderIsUsedAsFallback()
    {
        $_SERVER["REDIRECT_HTTP_X_FORWARDED"] = "yes";

        $this->assertSame("yes", RequestHeader::getHeader("x-forwarded"));
    }

    public function testDirectHeaderWinsOverRedirectFallback()
    {
        $_SERVER["HTTP_X_FORWARDED"] = "direct";
        $_SERVER["REDIRECT_HTTP_X_FORWARDED"] = "redirect";

        $this->assertSame("direct", RequestHeader::getHeader("x-forwarded"));
    }

    // ---- RequestParam::extractValueFromGlobals ----

    public function testUnsupportedRequestMethodYieldsNull()
    {
        $this->assertNull(RequestParam::extractValueFromGlobals("HEAD", "anything"));
        $this->assertNull(RequestParam::extractValueFromGlobals("OPTIONS", "anything"));
    }

    public function testMissingValuesYieldNull()
    {
        // No query string / body in the CLI, so every method has nothing to read
        foreach(["GET", "POST", "PUT", "PATCH", "DELETE"] as $method)
            $this->assertNull(RequestParam::extractValueFromGlobals($method, "absent"), $method);
    }

    public function testContentTypeIsCachedAfterFirstRead()
    {
        $_SERVER["CONTENT_TYPE"] = "application/json";
        RequestParam::extractValueFromGlobals("GET", "x");

        $_SERVER["CONTENT_TYPE"] = "text/plain";
        RequestParam::extractValueFromGlobals("GET", "x");

        $this->assertSame("application/json", RequestParam::$contentType);
    }
}
