<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

/**
 * OptRequestParam
 */
class OptRequestParam extends RequestParam
{
    public function __construct(array $opts = [])
    {
        $opts["required"] = array_key_exists("required", $opts) ? $opts["required"] : false;

        return parent::__construct($opts);
    }
}