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
            unset($attributes['controller']);
            unset($attributes['_route']);
            $attributes['body'] = $request->getContent() ?: null;
            $attributes['params'] = $request->query->all() ?: null;
            $response = call_user_func_array($controller, $attributes);
        } catch (ResourceNotFoundException $e) {
            $response = new Response(content: 'Route not found!', status: Response::HTTP_NOT_FOUND);
        } catch (Exception $e) {
            $response = new Response($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $response;
    }

    // Associates a URL with a callback function
    /**
     * @param string $path
     * @param callable $controller
     */
    public function map(string $path, callable $controller): void
    {
        $this->routes->add($path, new Route(
            $path,
            array('controller' => $controller)
        ));
    }

    /**
     * @param array $routes
     */
    public function multiMap(array $routes): void
    {
        foreach ($routes as $path => $callable) {
            $this->map($path, $callable);
        }
    }
}
