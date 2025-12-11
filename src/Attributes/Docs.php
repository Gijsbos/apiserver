<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Attributes;

use Attribute;

/**
 * Docs
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Docs extends DocsProperty
{
    public function __construct(private string $name, private mixed $description)
    {}

    public function getName()
    {
        return $this->name;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function export() : array
    {
        return [
            "name" => $this->name,
            "description" => $this->description,
        ];
    }
}