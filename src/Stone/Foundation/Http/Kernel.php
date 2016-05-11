<?php

namespace Stone\Foundation\Http;

use Illuminate\Contracts\Http\Kernel as KernelContract;
use Stone\Contracts\RequestHandler;
use Stone\FastCGI\Server;
use Stone\Foundation\Bootstrap\BootRequestProviders;
use Stone\Snap\Runkit;
use Illuminate\Routing\Router;
use Illuminate\Routing\Pipeline;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Exception;

class Kernel implements KernelContract, RequestHandler
{
    /**
     * The application implementation.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * The router instance.
     *
     * @var \Illuminate\Routing\Router
     */
    protected $router;

    /**
     * The bootstrap classes for the application.
     *
     * @var array
     */
    protected $bootstrappers = [
        'Illuminate\Foundation\Bootstrap\DetectEnvironment',
        'Illuminate\Foundation\Bootstrap\LoadConfiguration',
        'Illuminate\Foundation\Bootstrap\ConfigureLogging',
        'Illuminate\Foundation\Bootstrap\HandleExceptions',
        'Illuminate\Foundation\Bootstrap\RegisterFacades',
        'Stone\Foundation\Bootstrap\BootStone',
        'Illuminate\Foundation\Bootstrap\RegisterProviders',
        'Illuminate\Foundation\Bootstrap\BootProviders',
    ];

    /**
     * The application's middleware stack.
     *
     * @var array
     */
    protected $middleware = [];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [];

    /**
     * The application's route middleware.
     *
     * @var array
     */
    protected $routeMiddleware = [];

    /**
     * Create a new HTTP kernel instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @param  \Illuminate\Routing\Router  $router
     * @return void
     */
    public function __construct(Application $app, Router $router)
    {
        $this->app = $app;
        $this->router = $router;

        foreach ($this->middlewareGroups as $key => $middleware) {
            $router->middlewareGroup($key, $middleware);
        }

        foreach ($this->routeMiddleware as $key => $middleware) {
            $router->middleware($key, $middleware);
        }
    }

    public function handle($request)
    {
        $this->app->instance('request', $request);
        $this->app->bootstrapWith($this->bootstrappers());

        try {
            $config = Config::get('stone.web');
            $config['daemonize'] = false;

            if ($this->option('debug')) {
                $config['daemonize'] = false;
            }

            $server = new Server($config, $this);

            if ($this->option('reload')) {
                if ($server->reload()) {
                    return $this->info('reload the server success!');
                }
            }

            if ($this->option('stop')) {
                if ($server->stop()) {
                    return $this->info('stop the server success!');
                }
            }

            $this->injectInstance();

            $this->snapApp();

            $server->start();

        } catch (Exception $e) {
            // 错误日志很可能在php默认log里，
            // 这里的错误不能被业务层捕获， 因为框架可能还没完全初始化完毕
            echo strval($e);
        }
    }

    public function injectInstance()
    {
        Runkit::addSnapMethods(get_class($this->app));

        $snapBindings = Config::get('stone.web.snap_bindings');

        foreach ($snapBindings as $item) {
            Runkit::addSnapMethods(get_class($this->app->make($item)));
        }
    }

    /**
     * snapApp
     * snap current state
     *
     * @return void
     */
    public function snapApp()
    {
        $repository = $this->app->make('snap');
        $snapBindings = Config::get('stone.web.snap_bindings');

        foreach ($snapBindings as $item) {
            $this->app->make($item)->snapNow($repository);
        }

        $this->app->snapNow($repository);
        Facade::snapStaticNow(Facade::class, $repository);
    }

    public function restoreSnap()
    {
        $repository = $this->app->make('snap');
        $snapBindings = Config::get('stone.web.snap_bindings');

        foreach ($snapBindings as $item) {
            $this->app->make($item)->restoreSnap($repository);
        }

        $this->app->restoreSnap($repository);
        Facade::restoreStaticSnap(Facade::class, $repository);
    }

    public function bootstrap()
    {
    }

    public function requestBootstrap()
    {
        $bootstrapper = BootRequestProviders::class;
        $this->app['events']->fire('request-bootstrapping: '.$bootstrapper, [$this->app]);
        $this->app->make($bootstrapper)->bootstrap($this->app);
        $this->app['events']->fire('request-bootstrapped: '.$bootstrapper, [$this->app]);
    }

    public function terminate($request, $response)
    {
        $middlewares = $this->app->shouldSkipMiddleware() ? [] : array_merge(
            $this->gatherRouteMiddlewares($request),
            $this->middleware
        );

        foreach ($middlewares as $middleware) {
            list($name, $parameters) = $this->parseMiddleware($middleware);

            $instance = $this->app->make($name);

            if (method_exists($instance, 'terminate')) {
                $instance->terminate($request, $response);
            }
        }

        $this->app->terminate();
        $this->restoreSnap();
    }

    /**
     * Gather the route middleware for the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    protected function gatherRouteMiddlewares($request)
    {
        if ($route = $request->route()) {
            return $this->router->gatherRouteMiddlewares($route);
        }

        return [];
    }

    /**
     * Parse a middleware string to get the name and parameters.
     *
     * @param  string  $middleware
     * @return array
     */
    protected function parseMiddleware($middleware)
    {
        list($name, $parameters) = array_pad(explode(':', $middleware, 2), 2, []);

        if (is_string($parameters)) {
            $parameters = explode(',', $parameters);
        }

        return [$name, $parameters];
    }

    public function onWorkerStart()
    {

    }

    public function process()
    {
        $request = \Illuminate\Http\Request::capture();
        $this->app->instance('request', $request);
        $this->requestBootstrap();

        try {
            $request->enableHttpMethodParameterOverride();

            $response = $this->sendRequestThroughRouter($request);
        } catch (Exception $e) {
            $this->reportException($e);

            $response = $this->renderException($request, $e);
        } catch (Throwable $e) {
            $this->reportException($e = new FatalThrowableError($e));

            $response = $this->renderException($request, $e);
        }

        $this->app['events']->fire('kernel.handled', [$request, $response]);

        $this->terminate($request, $response);

        return $response;
    }

    /**
     * Send the given request through the middleware / router.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    protected function sendRequestThroughRouter($request)
    {
        $this->app->instance('request', $request);

        Facade::clearResolvedInstance('request');

        $this->bootstrap();

        return (new Pipeline($this->app))
                    ->send($request)
                    ->through($this->app->shouldSkipMiddleware() ? [] : $this->middleware)
                    ->then($this->dispatchToRouter());
    }

    /**
     * Get the route dispatcher callback.
     *
     * @return \Closure
     */
    protected function dispatchToRouter()
    {
        return function ($request) {
            $this->app->instance('request', $request);

            return $this->router->dispatch($request);
        };
    }

    public function getApplication()
    {
        return $this->app;
    }

    /**
     * Get the bootstrap classes for the application.
     *
     * @return array
     */
    protected function bootstrappers()
    {
        return $this->bootstrappers;
    }

    public function option()
    {
        return false;
    }

    /**
     * Report the exception to the exception handler.
     *
     * @param  \Exception  $e
     * @return void
     */
    protected function reportException(Exception $e)
    {
        $this->app[ExceptionHandler::class]->report($e);
    }

    /**
     * Render the exception to a response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Exception  $e
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function renderException($request, Exception $e)
    {
        return $this->app[ExceptionHandler::class]->render($request, $e);
    }

    /**
     * handleException
     * 异常处理
     *
     * @param mixed $e
     * @return Response
     */
    public function handleException($e)
    {
        $this->reportException($e);
        $response = $this->renderException($this->app['request'], $e);
        return $response;
    }
}
