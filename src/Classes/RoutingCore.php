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

        try {
            $attributes = $matcher->match($request->getPathInfo());
            $isPublic = $attributes['public'] ?? false;
            $isAdminOnly = $attributes['admin'] ?? false;
            $controller = $attributes['controller'];
            $isHtml = $attributes['html'] ?? false;

            // Security Check
            if (!$isPublic && !$this->isAuthorized($request, $isAdminOnly)) {
                return new Response(json_encode([
                    'status' => 'error',
                    'error' => 'Unauthorized: Access denied'
                ]), Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'application/json']);
            }

            unset($attributes['controller'], $attributes['_route'], $attributes['html'], $attributes['public'], $attributes['admin']);
            $attributes['body'] = $request->getContent() ?: null;
            $attributes['params'] = $request->query->all() ?: null;

            $response = call_user_func_array($controller, $attributes);

            if (!$isHtml && !$response->headers->has('Content-Type')) {
                $response->headers->set('Content-Type', 'application/json');
            }
        } catch (ResourceNotFoundException) {
            $html = file_get_contents(__DIR__ . '/../views/404.html');
            $response = new Response($html, Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/html']);
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
        $providedKey = $request->headers->get('X-API-Key');
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

        if ($isAdminOnly) {
            return $adminKey !== null && $providedKey === $adminKey;
        }

        // Public/Report routes accept both admin and regular keys
        $validKeys = [];
        if ($appKey) $validKeys = array_merge($validKeys, array_map('trim', explode(',', $appKey)));
        if ($adminKey) $validKeys[] = trim($adminKey);

        return !empty($validKeys) && in_array($providedKey, $validKeys, true);
    }

    public function map(string $path, string $httpMethod, callable $controller, bool $public = false, bool $html = false, bool $admin = false): void
    {
        $routes = new RouteCollection();
        $routes->add($path, new Route(
            $path,
            array('controller' => $controller, 'public' => $public, 'html' => $html, 'admin' => $admin)
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
                $data['admin'] ?? false
            );
        }
    }
}
