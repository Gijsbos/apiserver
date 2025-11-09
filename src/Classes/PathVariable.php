<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

/**
 * PathVariable
 */
class PathVariable
{
    public $value;

    public function __construct(private string $name, private Route $route)
    {
       $this->value = $this->extractPathValue($route);
    }

    private function extractPathValue()
    {
        return $this->route->getPathVariables($this->name);
    }
}