<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Interfaces;

use gijsbos\ApiServer\Attributes\Route;

/**
 * AuthorityCheckInterface
 *  Implemented by anything that decides whether the current request is
 *  authorized. execute() throws to deny (any exception, whatever message
 *  fits) and returns normally to allow. $route is core routing plumbing
 *  (e.g. to stash extracted data via Route::setData() for the controller to
 *  read later) - beyond that, this package has no opinion on what a check
 *  inspects (a token, a session, anything else); the implementation is
 *  responsible for looking up whatever it needs itself.
 */
interface AuthorityCheckInterface
{
    public function execute(Route $route) : void;
}
