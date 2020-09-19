<?php

namespace XHGui\Middleware;

use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\App;

class RenderMiddleware
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function __invoke(Request $req, Response $res, callable $next)
    {
        $app = $this->app;

        // Run the controller action/route function
        $next();

        // Render the template.
        if (isset($app->controller)) {
            $app->controller->render();
        }
    }
}
