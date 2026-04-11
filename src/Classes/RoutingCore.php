<?php

namespace Classes;

use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class RoutingCore implements HttpKernelInterface
{
    /**
     * @var RouteCollection
     */
    protected RouteCollection $routes;

    /**
     * RoutingCore constructor.
     */
    public function __construct()
    {
        $this->routes = new RouteCollection();
    }

    /**
     * @param Request $request
     * @param int $type
     * @param bool $catch
     * @return Response
     * @throws Exception
     */
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $context = new RequestContext();
        $context->fromRequest($request);

        $matcher = new UrlMatcher($this->routes, $context);

        // --- PHASE 4: Intelligent Rate Limiting ---
        if ($this->shouldRateLimit($request)) {
            $limitResponse = $this->checkRateLimit($request);
            if ($limitResponse) return $limitResponse;
        }
        // ------------------------------------------


        try {
            $trimmedPath = trim(urldecode($request->getPathInfo()), " \t\n\r\0\x0B");
            $attributes = $matcher->match($trimmedPath);
            $isPublic = $attributes['public'] ?? false;
            $isAdminOnly = $attributes['admin'] ?? false;
            $controller = $attributes['controller'];
            $isHtml = $attributes['html'] ?? false;

            // Security Check
            // API calls are always protected. HTML pages let the frontend Ghost Guard handle it.
            if (!$isPublic && !$isHtml && !$this->isAuthorized($request, $isAdminOnly)) {
                return new Response(json_encode([
                    'status' => 'error',
                    'error' => 'Unauthorized: Access denied'
                ]), Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'application/json']);
            }

            unset($attributes['controller'], $attributes['_route'], $attributes['html'], $attributes['public'], $attributes['admin']);
            $attributes['body'] = $request->getContent() ?: null;
            $attributes['params'] = $request->query->all() ?: null;
            $attributes['request'] = $request;

            $response = call_user_func_array($controller, $attributes);

            if (!$isHtml && !$response->headers->has('Content-Type')) {
                $response->headers->set('Content-Type', 'application/json');
            }
        } catch (ResourceNotFoundException) {
            $html = file_get_contents(__DIR__ . '/../views/404.html');
            $response = new Response($html, Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/html']);
        } catch (\Symfony\Component\Routing\Exception\MethodNotAllowedException $e) {
            $response = new Response(json_encode([
                'status' => 'error',
                'error' => 'Method Not Allowed',
                'message' => 'The ' . $request->getMethod() . ' method is not supported for this route. Supported methods: ' . implode(', ', $e->getAllowedMethods())
            ]), Response::HTTP_METHOD_NOT_ALLOWED, ['Content-Type' => 'application/json']);
        } catch (Exception $e) {
            $response = new Response(json_encode([
                'status' => 'error',
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ]), Response::HTTP_INTERNAL_SERVER_ERROR, ['Content-Type' => 'application/json']);
        }

        return $response;
    }

    private function isAuthorized(Request $request, bool $isAdminOnly = false): bool
    {
        // 1. IP Whitelisting
        $authorizedIps = \Helpers\Helpers::getAuthorizedIps();
        if (!empty($authorizedIps)) {
            $clientIp = $request->getClientIp();
            if (!\Symfony\Component\HttpFoundation\IpUtils::checkIp($clientIp, $authorizedIps)) {
                return false;
            }
        }

        // 2. Token Check
        $providedKey = $request->headers->get('X-API-Key') ?: $request->headers->get('X-Admin-API-Key');
        if ($providedKey === null) {
            $authHeader = $request->headers->get('Authorization');
            if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                $providedKey = substr($authHeader, 7);
            }
        }
        
        // Check query parameters for manual browser access
        if ($providedKey === null) {
            $providedKey = $request->query->get('key') ?: $request->query->get('token');
        }

        $providedKey = $providedKey ? trim($providedKey) : null;

        $appKey = \Helpers\Helpers::getAppApiKey();
        $adminKey = \Helpers\Helpers::getAdminApiKey();

        // DEPLOYMENT DEMO BYPASS:
        // Automatically authorize regular users IF AND ONLY IF the environment is explicitly set to 'demo'.
        if (\Helpers\Helpers::isDemo()) {
            // In demo mode, we inject the internal Admin Key so all APIs (CRUD, Updates) work.
            $adminKey = \Helpers\Helpers::getAdminApiKey();
            
            if ($adminKey) {
                $request->headers->set('X-API-Key', $adminKey);
                $request->headers->set('Authorization', 'Bearer ' . $adminKey);
                $request->attributes->set('BYPASS_INTERNAL_AUTH', true);
            }
            return true;
        }

        if ($isAdminOnly) {
            return $adminKey !== null && $providedKey === $adminKey;
        }

        // Public/Report routes accept both admin and regular keys
        $validKeys = [];
        if ($appKey) $validKeys = array_merge($validKeys, array_map('trim', explode(',', $appKey)));
        if ($adminKey) $validKeys[] = trim($adminKey);

        return !empty($validKeys) && in_array($providedKey, $validKeys, true);
    }

    /**
     * Determine if we should apply rate limiting to this request.
     */
    private function shouldRateLimit(Request $request): bool
    {
        // Don't rate limit health checks or local dev (if needed)
        if ($request->getPathInfo() === '/api/heartbeat') return false;
        if (\Helpers\Helpers::isDemo()) return false;
        return true;
    }

    /**
     * Check the rate limit in Redis and return a 429 response if exceeded.
     */
    private function checkRateLimit(Request $request): ?Response
    {
        try {
            $redis = \Helpers\Helpers::getRedisClient();
            if (!$redis) return null;

            $ip = $request->getClientIp();
            $isAdmin = $this->isAuthorized($request, true);
            
            // Tiered Limits: Admin/Facade gets 200/min, others 60/min
            $limit = $isAdmin ? 200 : 60;
            $window = 60; // 1 minute
            
            $key = "rate_limit:{$ip}";
            $current = $redis->get($key);

            if ($current && (int)$current >= $limit) {
                return new Response(json_encode([
                    'status' => 'error',
                    'error' => 'Too Many Requests',
                    'message' => 'Rate limit exceeded. Please try again later.'
                ]), Response::HTTP_TOO_MANY_REQUESTS, ['Content-Type' => 'application/json']);
            }

            if (!$current) {
                $redis->setex($key, $window, 1);
            } else {
                $redis->incr($key);
            }
        } catch (Exception $e) {
            // If Redis fails, we prefer to let the request through but log it
            error_log("Rate limiting failed: " . $e->getMessage());
        }

        return null;
    }


    public function map(
        string $path,
        string $httpMethod,
        callable $controller,
        bool $public = false,
        bool $html = false,
        bool $admin = false,
        array $requirements = [],
        array $defaults = []
    ): void {
        $routes = new RouteCollection();
        $routes->add($path, new Route(
            $path,
            array_merge(['controller' => $controller, 'public' => $public, 'html' => $html, 'admin' => $admin], $defaults),
            $requirements
        ));
        $routes->setMethods($httpMethod);
        $this->routes->addCollection($routes);
    }

    /**
     * @param array $routes
     */
    public function multiMap(array $routes): void
    {
        foreach ($routes as $path => $data) {
            $this->map(
                $path, 
                $data['httpMethod'], 
                $data['callable'], 
                $data['public'] ?? false, 
                $data['html'] ?? false,
                $data['admin'] ?? false,
                $data['requirements'] ?? [],
                $data['defaults'] ?? []
            );
        }
    }
}
