<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use gijsbos\ApiServer\Authorization\AuthorizationHeaderParser;
use gijsbos\Http\Exceptions\InternalServerErrorException;
use gijsbos\Http\Exceptions\UnauthorizedException;

/**
 * SecurityContext
 *  Path-based authentication rules, evaluated in registration order (first
 *  matching pattern wins) - register more specific patterns before general
 *  ones. Unmatched paths require authentication by default.
 *
 *  How a required credential is actually verified is NOT this class's concern -
 *  authenticate() only decides whether to enforce it, then delegates to
 *  AuthorizationHeaderVerifier, which is configured separately (e.g. its viaBearer
 *  callback, passed to the Server). That indirection is what keeps this class
 *  free of any OAuth2/JWT specifics. It also means a path that requires auth
 *  fails closed (HTTP 500, a setup mistake rather than a client error) when a
 *  credential is presented but the Server has no AuthorizationHeaderVerifier.
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
    private null|\WeakReference $executedFor;

    public function __construct()
    {
        $this->rules = [];
        $this->executedFor = null;
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
        // Prevent executing twice for one request. Tracked per Server (one per request) rather than
        // as a flag on this instance, because Server::$securityContext is static and would otherwise
        // carry a previous request's success into every later request of a long-running worker.
        if($this->executedFor?->get() === $server)
            return;

        if(!$this->requiresAuth($server->getRequestURI()))
            return;

        $credentials = new AuthorizationHeaderParser()->parse();

        if($credentials === null)
            throw new UnauthorizedException("authorizationRequired", "Authorization required");

        $authorizationHeaderVerifier = $server->getAuthorizationHeaderVerifier();

        // Nothing can vouch for the credential: deny rather than let any well-formed header through
        if($authorizationHeaderVerifier === null)
            throw new InternalServerErrorException("authenticationNotConfigured", "Path \"".$server->getRequestURI()."\" requires authentication but no AuthorizationHeaderVerifier is configured; pass one as the 'authorizationHeaderVerifier' option to Server or call Server::setAuthorizationHeaderVerifier()");

        $server->setAuthorizationResult($authorizationHeaderVerifier->verify($credentials));

        $this->executedFor = \WeakReference::create($server);
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
