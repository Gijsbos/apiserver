<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

use Attribute;

/**
 * OptionsRoute
 */
#[Attribute(Attribute::TARGET_METHOD)]
class OptionsRoute extends Route
{
    /**
     * __construct
     */
    public function __construct(private string $path)
    {
        parent::__construct("OPTIONS", $path);
    }
}