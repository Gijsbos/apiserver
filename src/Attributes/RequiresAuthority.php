<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Attributes;

use Attribute;
use gijsbos\ApiServer\Interfaces\AuthorityCheckInterface;
use InvalidArgumentException;
use Override;

/**
 * RequiresAuthority
 *  Runs a developer-supplied AuthorityCheckInterface before the route
 *  resolves. execute() throws to deny (whatever exception/message fits)
 *  and returns normally to allow. This attribute is deliberately ignorant
 *  of what a check actually inspects (a token, a session, anything else) -
 *  that's entirely up to the AuthorityCheckInterface implementation.
 *
 *  $check accepts either:
 *   - a class name implementing AuthorityCheckInterface with a no-arg
 *     constructor - instantiated directly, or
 *   - a callable (string "Class::method" or array [Class::class, 'method'])
 *     returning an AuthorityCheckInterface instance - use this when the
 *     check needs constructor arguments.
 *
 *  PHP attribute arguments must be compile-time constant expressions, so
 *  $check cannot be a closure when used directly as an attribute - reference
 *  a class name or a static method. Subclasses that call parent::__construct()
 *  at runtime (not through attribute syntax) aren't bound by that restriction.
 *
 *  $authority is handed to the check untouched, this attribute does not
 *  interpret it (e.g. required roles or scopes). The check receives the Route
 *  being executed as well, see AuthorityCheckInterface.
 *
 *  #[RequiresAuthority(IsAdminCheck::class, ['admin'])]
 *
 *  class IsAdminCheck implements AuthorityCheckInterface
 *  {
 *      public function execute(Route $route, array $authority)
 *      {
 *          if(!CurrentUser::hasAllAuthorities($authority))
 *              throw new ForbiddenException('insufficient_authority', 'Required authority: ' . implode(', ', $authority));
 *      }
 *  }
 */
#[Attribute(Attribute::TARGET_METHOD)]
class RequiresAuthority extends ExecuteBeforeRoute
{
    public function __construct(
        string|array|callable $check,
        array $authority,
    )
    {
        parent::__construct(function(Route $route) use ($check, $authority)
        {
            self::resolveAuthorityCheck($check)->execute($route, $authority);
        });
    }

    private static function resolveAuthorityCheck(string|array|callable $check) : AuthorityCheckInterface
    {
        if(is_string($check) && class_exists($check) && is_a($check, AuthorityCheckInterface::class, true))
            return new $check();

        if(is_callable($check))
        {
            $resolved = $check();

            if($resolved instanceof AuthorityCheckInterface)
                return $resolved;

            throw new InvalidArgumentException("The callable passed to RequiresAuthority must return an object implementing AuthorityCheckInterface");
        }

        throw new InvalidArgumentException("RequiresAuthority expects a class name implementing AuthorityCheckInterface, or a callable returning one");
    }
}
