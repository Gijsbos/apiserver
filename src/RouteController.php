<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

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