<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use Error;
use ReflectionMethod;
use gijsbos\ApiServer\Classes\PathVariable;
use gijsbos\ApiServer\Classes\RequestHeader;
use gijsbos\ApiServer\Classes\RequestParam;
use gijsbos\ApiServer\Classes\ReturnFilter;
use gijsbos\ApiServer\Classes\Route;
use gijsbos\ApiServer\Interfaces\RouteInterface;
use gijsbos\ApiServer\Utils\RouteParser;
use gijsbos\Logging\Classes\LogEnabledClass;

/**
 * RouteController
 */
class RouteController extends LogEnabledClass
{
    const CONTROLLER_METHOD_PARAM_TYPES = [RequestHeader::class, RequestParam::class, PathVariable::class];
    
    public function __construct(private null|Server $server = null, array $opts = [])
    {
        $opts["mode"] = LogEnabledClass::MODE_METADATA; // Enable Meta Data Read and Write for passing on RouterController data to Routes

        parent::__construct($opts);
    }

    public function setServer(Server $server)
    {
        $this->server = $server;
    }

    public function getServer() : null|Server
    {
        return $this->server;
    }

    public function setLocalRoute()
    {
        throw new Error("Method not allowed");
    }

    public function getLocalRoute()
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
        $class = @$trace["class"];
        $function = @$trace["function"];

        $attributes = RouteParser::getReflectionMethodAttributeOfSubclass(new ReflectionMethod($class, $function), Route::class);

        if(count($attributes) == 0)
            return null;

        $route = reset($attributes)->newInstance();

        $route->setClassName($class);
        $route->setMethodName($function);

        $route->setAttributes(Route::extractRouteAttributes($class, $function));

        return $route;
    }

    public function setReturnFilter()
    {
        throw new Error("Method not allowed");
    }

    public function getReturnFilter()
    {
        return $this->getServer()?->getRoute()?->getAttributes(ReturnFilter::class)?->newInstance();
    }

    public function setReturnFilterData()
    {
        throw new Error("Method not allowed");
    }

    public function getReturnFilterData() : null | array
    {
        return $this->getServer()?->getRoute()?->getAttributes(ReturnFilter::class)?->newInstance()->getFilter();
    }

    public function setRoute()
    {
        throw new Error("Method not allowed");
    }

    public function getRoute() : null | RouteInterface
    {
        return $this->getServer()?->getRoute();
    }
}