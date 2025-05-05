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
            $controller = $attributes['controller'];
            $isHtml = $attributes['html'] ?? false; // Detect if HTML

            unset($attributes['controller'], $attributes['_route'], $attributes['html']);
            $attributes['body'] = $request->getContent() ?: null;
            $attributes['params'] = $request->query->all() ?: null;

            $response = call_user_func_array($controller, $attributes);

            // If not HTML, force Content-Type JSON
            if (!$isHtml && !$response->headers->has('Content-Type')) {
                $response->headers->set('Content-Type', 'application/json');
            }
        } catch (ResourceNotFoundException) {
            $html = file_get_contents(__DIR__ . '/../views/404.html');
            $response = new Response($html, Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/html']);
        } catch (Exception $e) {
            $response = new Response($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR, headers: [
                'Content-Type' => 'application/json'
            ]);
        }

        return $response;
    }

    // Associates a URL with a callback function

    /**
     * @param string $path
     * @param string $httpMethod
     * @param callable $controller
     */
    public function map(string $path, string $httpMethod, callable $controller): void
    {
        $routes = new RouteCollection();
        $routes->add($path, new Route(
            $path,
            array('controller' => $controller)
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
            $this->map($path, $data['httpMethod'], $data['callable']);
        }
    }
}
