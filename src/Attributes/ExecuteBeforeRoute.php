<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Attributes;

use Attribute;

/**
 * ExecuteBeforeRoute
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ExecuteBeforeRoute extends RouteAttribute
{
    public function __construct(private mixed $callback)
    { }

    public function getCallback()
    {
        return $this->callback;
    }

    public function execute(Route $route)
    {
        if(is_callable($callback = $this->callback))
            $callback($route);
    }
}