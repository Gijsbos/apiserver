<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Attributes;

use Attribute;

/**
 * GetRoute
 */
#[Attribute(Attribute::TARGET_METHOD)]
class GetRoute extends Route
{
    public function __construct(private string $path, int $status = 200)
    {
        parent::__construct("GET", $path, $status);
    }
}