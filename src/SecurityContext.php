<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use gijsbos\ApiServer\Authentication\AuthenticationHeaderParser;
use gijsbos\ApiServer\Authentication\AuthenticationVerifier;
use gijsbos\Http\Exceptions\UnauthorizedException;

/**
 * SecurityContext
 *  Path-based authentication rules, evaluated in registration order (first
 *  matching pattern wins) - register more specific patterns before general
 *  ones. Unmatched paths require authentication by default.
 *
 *  How a required credential is actually verified is NOT this class's concern -
 *  authenticate() only decides whether to enforce it, then delegates to
 *  AuthenticationVerifier, which is configured separately (e.g. via
 *  AuthenticationVerifier::$viaBearer). That indirection is what keeps this
 *  class free of any OAuth2/JWT specifics.
 *
 *  $securityContext = new SecurityContext()
 *      ->permitAll("/health", "/.well-known/**")
 *      ->requireAuth("/user/**");
 *
 *  Server::$securityContext = $securityContext;
 */
final class SecurityContext
{
    private array $rules;
    private bool $executed;

    public function __construct()
    {
        $this->rules = [];
        $this->executed = false;
    }

    public function permitAll(string ...$patterns) : static
    {
        foreach($patterns as $pattern)
            $this->rules[] = ["pattern" => $pattern, "requiresAuth" => false];

        return $this;
    }

    public function requireAuth(string ...$patterns) : static
    {
        foreach($patterns as $pattern)
            $this->rules[] = ["pattern" => $pattern, "requiresAuth" => true];

        return $this;
    }

    public function requiresAuth(string $path) : bool
    {
        foreach($this->rules as $rule)
            if($this->matches($rule["pattern"], $path))
                return $rule["requiresAuth"];

        return true;
    }

    /**
     * authenticate
     *  Called by Server before route resolution. Throws when the path
     *  requires auth and no valid credential is presented.
     */
    public function authenticate(Server $server) : void
    {
        if($this->executed) // Prevent executing twice
            return;

        if(!$this->requiresAuth($server->getRequestURI()))
            return;

        $credentials = new AuthenticationHeaderParser()->parse();

        if($credentials === null)
            throw new UnauthorizedException("authorizationRequired", "Authorization required");

        $server->getAuthenticationVerifier()?->verify($credentials);

        $this->executed = true;
    }

    private function matches(string $pattern, string $path) : bool
    {
        // Server::getRequestURI() never has a leading slash, but patterns are
        // naturally written with one (e.g. "/user/**") - normalize both.
        $pattern = ltrim($pattern, '/');
        $path = ltrim($path, '/');

        $regex = strtr(preg_quote($pattern, '#'), [
            '\*\*' => '.*',
            '\*' => '[^/]*',
        ]);

        return preg_match("#^{$regex}$#", $path) === 1;
    }
}
