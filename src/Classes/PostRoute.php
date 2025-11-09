<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

use Attribute;

/**
 * PostRoute
 */
#[Attribute(Attribute::TARGET_METHOD)]
class PostRoute extends Route
{
    /**
     * __construct
     */
    public function __construct(private string $path, int $status = 201)
    {
        parent::__construct("POST", $path, $status);
    }
}