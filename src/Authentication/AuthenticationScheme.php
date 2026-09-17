<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Authentication;

/**
 * AuthenticationScheme
 */
enum AuthenticationScheme: string
{
    case Bearer = 'bearer';
    case Basic = 'basic';
}
