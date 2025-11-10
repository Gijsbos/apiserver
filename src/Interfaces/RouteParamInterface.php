<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Interfaces;

use gijsbos\ApiServer\Classes\Route;

interface RouteParamInterface
{
    public static function createWithoutConstructor(string $name, Route $route);
}