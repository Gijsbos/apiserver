<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use gijsbos\ApiServer\Classes\PathVariable;
use gijsbos\ApiServer\Classes\RequestHeader;
use gijsbos\ApiServer\Classes\RequestParam;
use gijsbos\ApiServer\Interfaces\RouteInterface;

/**
 * RouteController
 */
class RouteController
{
    const CONTROLLER_METHOD_PARAM_TYPES = [RequestHeader::class, RequestParam::class, PathVariable::class];
    
    public function __construct(private null|Server $server = null)
    { }

    public function getServer() : null|Server
    {
        return $this->server;
    }

    public function getRoute() : null | RouteInterface
    {
        return $this->getServer()?->getRoute();
    }
}