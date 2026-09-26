<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Published
{
    public function __construct(private bool $published)
    { }

    public function isPublished()
    {
        return $this->published;
    }
}