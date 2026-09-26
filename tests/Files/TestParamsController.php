<?php
declare(strict_types=1);

use gijsbos\ApiServer\Attributes\Docs;
use gijsbos\ApiServer\Attributes\ExampleResponse;
use gijsbos\ApiServer\Attributes\ExecuteBeforeRoute;
use gijsbos\ApiServer\Attributes\GetRoute;
use gijsbos\ApiServer\Attributes\PostRoute;
use gijsbos\ApiServer\Attributes\RequiresAuthority;
use gijsbos\ApiServer\Attributes\ReturnFilter;
use gijsbos\ApiServer\Attributes\Route;
use gijsbos\ApiServer\Classes\OptRequestParam;
use gijsbos\ApiServer\Classes\PathVariable;
use gijsbos\ApiServer\Classes\RequestHeader;
use gijsbos\ApiServer\Classes\RequestParam;
use gijsbos\ApiServer\Interfaces\RouteAuthorityVerifierInterface;
use gijsbos\ApiServer\RouteController;
use gijsbos\ExtFuncs\Attributes\RegExp;
use gijsbos\Http\Exceptions\ForbiddenException;

/**
 * Fixtures shared by the unit tests. Kept separate from TestController so the
 * routes the existing tests rely on stay untouched.
 */

enum TestSuit: string
{
    case Hearts = 'hearts';
    case Spades = 'spades';
}

class TestDto
{
    public string $name = "dto";
    public int $count = 3;
    private string $hidden = "hidden";
}

class TestFilterAddress
{
    public string $city = "";
    public string $zip = "";
}

class TestFilterUser
{
    public string $name = "";
    public string $email = "";

    /** @var TestFilterAddress */
    public $address;
}

class TestPatternFixture
{
    #[RegExp('/^\d{3}$/')]
    public string $code = "";

    public string $plain = "";

    public static function pattern() : string
    {
        return '/^[a-z]+$/';
    }

    public static function notAString() : int
    {
        return 1;
    }
}

/**
 * Records every authority list it is asked to check, and stashes it on the route.
 */
class TestAllowAuthorityCheck implements RouteAuthorityVerifierInterface
{
    public static array $calls = [];

    public function execute(Route $route, array $authority)
    {
        self::$calls[] = ["route" => $route, "authority" => $authority];

        $route->addData("authority", $authority);
    }
}

class TestDenyAuthorityCheck implements RouteAuthorityVerifierInterface
{
    public function execute(Route $route, array $authority)
    {
        throw new ForbiddenException("insufficientAuthority", "Required authority: " . implode(",", $authority));
    }
}

class TestAuthorityCheckFactory
{
    public static function make() : RouteAuthorityVerifierInterface
    {
        return new TestAllowAuthorityCheck();
    }

    public static function makeInvalid() : object
    {
        return new stdClass();
    }
}

/**
 * Records every route it runs for, through a subclass of ExecuteBeforeRoute
 * (as RequiresAuthority is).
 */
#[Attribute(Attribute::TARGET_METHOD)]
class TestRecordingBeforeRoute extends ExecuteBeforeRoute
{
    public function __construct()
    {
        parent::__construct(function(Route $route)
        {
            TestRouteShapesFixture::$beforeRouteCalls[] = $route;
        });
    }
}

/**
 * Plain class (not a RouteController) so it can carry shapes RouteParser must
 * reject without breaking route generation for the other fixtures.
 */
class TestRouteShapesFixture
{
    public static array $beforeRouteCalls = [];

    public static function recordDirect(Route $route) : void
    {
        self::$beforeRouteCalls[] = $route;
    }

    #[GetRoute('/shapes/single')]
    public function single()
    { }

    #[GetRoute('/shapes/double')]
    #[PostRoute('/shapes/double')]
    public function double()
    { }

    #[GetRoute('/shapes/before')]
    #[TestRecordingBeforeRoute]
    public function before()
    { }

    #[GetRoute('/shapes/direct-before')]
    #[ExecuteBeforeRoute('TestRouteShapesFixture::recordDirect')]
    public function directBefore()
    { }

    #[GetRoute('/shapes/bare-before')]
    #[ExecuteBeforeRoute]
    public function bareBefore()
    { }

    #[GetRoute('/shapes/not-callable-before')]
    #[ExecuteBeforeRoute('No\\Such\\Callback::method')]
    public function notCallableBefore()
    { }

    public function noRoute()
    { }
}

/**
 * TestParamsController
 */
#[Docs("Params", "Parameter handling fixtures")]
class TestParamsController extends RouteController
{
    #[GetRoute('/params/int/{id}')]
    #[Docs("intPathVariable", "Integer path variable")]
    #[ExampleResponse(["id" => 5])]
    public function intPathVariable(
        PathVariable|int $id = new PathVariable(["min" => 1, "max" => 100]),
    )
    {
        return ["id" => $id, "type" => gettype($id)];
    }

    #[GetRoute('/params/header')]
    public function header(
        RequestHeader|string $token = new RequestHeader(["required" => false, "default" => "none"]),
    )
    {
        return ["token" => $token];
    }

    #[GetRoute('/params/required')]
    public function requiredParam(
        RequestParam|string $name = new RequestParam(["required" => true]),
    )
    {
        return ["name" => $name];
    }

    #[GetRoute('/params/default')]
    public function defaultParam(
        RequestParam|string $name = new RequestParam(["required" => false, "default" => "john"]),
    )
    {
        return ["name" => $name];
    }

    #[GetRoute('/params/optional')]
    public function optionalParam(
        OptRequestParam|string $note = new OptRequestParam(),
    )
    {
        return ["note" => $note];
    }

    #[GetRoute('/params/enum/{suit}')]
    public function enumParam(
        PathVariable|TestSuit $suit = new PathVariable(),
    )
    {
        return ["suit" => $suit->value];
    }

    #[GetRoute('/params/float/{ratio}')]
    public function floatPathVariable(
        PathVariable|float $ratio = new PathVariable(["min" => 0.5, "max" => 2]),
    )
    {
        return ["ratio" => $ratio, "type" => gettype($ratio)];
    }

    #[GetRoute('/params/bool')]
    public function boolHeader(
        RequestHeader|bool $flag = new RequestHeader(["required" => false]),
    )
    {
        return ["flag" => $flag];
    }

    #[GetRoute('/params/json/{data}')]
    public function jsonPathVariable(
        PathVariable|array $data = new PathVariable(["customType" => "json"]),
    )
    {
        return ["decoded" => $data];
    }

    #[GetRoute('/params/base64/{data}')]
    public function base64PathVariable(
        PathVariable|string $data = new PathVariable(["customType" => "base64"]),
    )
    {
        return ["decoded" => $data];
    }

    #[GetRoute('/params/objects')]
    public function objects()
    {
        return [
            "date" => new DateTime('2024-01-02 03:04:05', new DateTimeZone('UTC')),
            "dto" => new TestDto(),
            "nested" => ["dto" => new TestDto()],
        ];
    }

    #[GetRoute('/params/escape')]
    public function escape()
    {
        return ["html" => '<b>"hi"</b> & bye', "count" => 3];
    }

    #[GetRoute('/params/filtered')]
    #[ReturnFilter(['name'])]
    public function filtered()
    {
        return ["name" => "a", "secret" => "b"];
    }

    #[GetRoute('/params/created', 201)]
    public function created()
    {
        return ["created" => true];
    }

    #[GetRoute('/params/boom')]
    public function boom()
    {
        throw new RuntimeException("boom");
    }

    #[GetRoute('/params/authority-allow')]
    #[RequiresAuthority(TestAllowAuthorityCheck::class, ['admin', 'write'])]
    public function authorityAllow()
    {
        return ["authority" => $this->getRoute()->getData("authority")];
    }

    #[GetRoute('/params/authority-deny')]
    #[RequiresAuthority(TestDenyAuthorityCheck::class, ['admin'])]
    public function authorityDeny()
    {
        return ["reached" => true];
    }
}
