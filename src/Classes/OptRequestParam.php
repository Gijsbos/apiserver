<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

/**
 * OptRequestParam
 */
class OptRequestParam extends RequestParam
{
    public function __construct(
        array $opts = [],
        null|string $type = null,
        null|string $customType = null,
        mixed $min = null,
        mixed $max = null,
        null|array|string $pattern = null,
        null|array $values = null,
        null|bool $required = null,
        mixed $default = null,
        null|string $description = null,
        null|string $docs = null,
    )
    {
        $opts["required"] = array_key_exists("required", $opts) ? $opts["required"] : false;

        return parent::__construct($opts, $type, $customType, $min, $max, $pattern, $values, $required, $default, $description, $docs);
    }
}