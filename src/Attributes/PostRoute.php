<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Attributes;

use Attribute;

/**
 * PostRoute
 */
#[Attribute(Attribute::TARGET_METHOD)]
class PostRoute extends Route
{
    public function __construct(private string $path, int $status = 201)
    {
        parent::__construct("POST", $path, $status);
    }
}