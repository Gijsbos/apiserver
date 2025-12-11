<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Attributes;

use Attribute;

/**
 * DocsProperty
 */
#[Attribute(Attribute::TARGET_METHOD)]
class DocsProperty extends RouteAttribute
{
    public function export() : array
    {
        return [];
    }
}