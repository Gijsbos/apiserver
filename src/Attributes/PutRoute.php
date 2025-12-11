<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Attributes;

use Attribute;

/**
 * PutRoute
 */
#[Attribute(Attribute::TARGET_METHOD)]
class PutRoute extends Route
{
    public function __construct(private string $path, int $status = 200)
    {
        parent::__construct("PUT", $path, $status);
    }
}