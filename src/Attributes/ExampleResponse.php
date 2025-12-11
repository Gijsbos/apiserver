<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Attributes;

use Attribute;

/**
 * ExampleResponse
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ExampleResponse extends DocsProperty
{
    public function __construct(private mixed $response)
    {}

    public function getResponse()
    {
        return $this->response;
    }

    public function export() : array
    {
        return [
            "exampleResponse" => $this->response,
        ];
    }
}