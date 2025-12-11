<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Attributes;

use Attribute;

/**
 * PatchRoute
 */
#[Attribute(Attribute::TARGET_METHOD)]
class PatchRoute extends Route
{
    public function __construct(private string $path, int $status = 200)
    {
        parent::__construct("PATCH", $path, $status);
    }
}