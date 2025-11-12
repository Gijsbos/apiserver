<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

use Attribute;

/**
 * ExecuteBeforeRoute
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ExecuteBeforeRoute
{
    /**
     * __construct
     */
    public function __construct(private mixed $callback)
    { }

    /**
     * getCallback
     */
    public function getCallback()
    {
        return $this->callback;
    }

    /**
     * execute
     */
    public function execute(Route $route)
    {
        if(is_callable($callback = $this->callback))
            $callback($route);
    }
}