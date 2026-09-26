<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Attributes;

use Attribute;
use LogicException;

#[Attribute(Attribute::TARGET_METHOD)]
class ExecuteBeforeRoute extends RouteAttribute
{
    public function __construct(private mixed $callback = null)
    { }

    public function getCallback()
    {
        return $this->callback;
    }

    public function execute(Route $route)
    {
        if($this->callback === null)
            throw new LogicException("ExecuteBeforeRoute on " . $route->getClassMethod() . " has no callback set");

        if(!is_callable($callback = $this->callback))
            throw new LogicException("ExecuteBeforeRoute on " . $route->getClassMethod() . " has a callback that is not callable");

        $callback($route);
    }
}