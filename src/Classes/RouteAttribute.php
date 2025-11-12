<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

use Attribute;

/**
 * RouteAttribute
 */
#[Attribute(Attribute::TARGET_METHOD)]
class RouteAttribute
{ }