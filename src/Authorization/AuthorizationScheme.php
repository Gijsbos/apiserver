<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Authorization;

/**
 * AuthorizationScheme
 */
enum AuthorizationScheme: string
{
    case Bearer = 'bearer';
    case Basic = 'basic';
}
