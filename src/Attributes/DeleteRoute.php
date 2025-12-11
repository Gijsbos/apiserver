<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Attributes;

use Attribute;

/**
 * DeleteRoute
 */
#[Attribute(Attribute::TARGET_METHOD)]
class DeleteRoute extends Route
{
    public function __construct(private string $path, int $status = 200)
    {
        parent::__construct("DELETE", $path, $status);
    }
}