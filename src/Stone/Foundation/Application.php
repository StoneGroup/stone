<?php namespace Stone\Foundation;

use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Illuminate\Support\Contracts\ResponsePreparerInterface;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Stone\Traits\SnapHelper;
use Stone\Contracts\RequestHandler;
use Stone\FastCGI\Server;
use Stone\Foundation\Bootstrap\BootRequestProviders;
use Stone\Snap\Runkit;
use Config;

class Application extends LaravelApplication implements HttpKernelInterface, TerminableInterface, ResponsePreparerInterface, RequestHandler
{
    use SnapHelper;

    /**
     * requestServiceProviders
     * service prociders booted every request
     *
     * @var array
     */
    protected $requestServiceProviders = [];

    public function run(SymfonyRequest $request = null)
    {
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

            $this->boot();

            $this->injectInstance();

            $this->snapApp();

            $server->start();

        } catch (\Exception $e) {
            var_dump(strval($e));
        }
    }

    public function injectInstance()
    {
        $snapBindings = Config::get('stone.web.snap_bindings');

        foreach ($this->getInstances() as $key => $item) {
            if (in_array($key, $snapBindings)) {
                Runkit::addSnapMethods(get_class($item));
            }
        }
    }

    public function process()
    {
        try {
            $this->restoreApp();
            $this->bootRequest();
            $request = $this->createNewRequest();
            $request->enableHttpMethodParameterOverride();
            $response = with($stack = $this->getStackedClient())->handle($request);
            $stack->terminate($request, $response);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }

        return $response;
    }

    public function onWorkerStart()
    {

    }

    public function option()
    {
        return false;
    }

	/**
	 * Boot the application's service providers.
	 *
	 * @return void
	 */
	public function boot()
	{
		if ($this->booted) return;

		array_walk($this->serviceProviders, function($p) {
            $p->boot();
        });

		$this->bootApplication();
	}

    /**
     * bootRequest
     * Boot the request's service providers.
     *
     * @return void
     */
    public function bootRequest()
    {
        $requestProviders = Config::get('app.request-providers');

        if (empty($requestServiceProviders)) {
            return;
        }

        $this->getRequestProviderRepository()->loadRequest($this, $requestProviders);

        foreach ($requestProviders as $provider) {
            $provider = new $provider($this);
            $provider->boot();
        }
    }

	/**
	 * Get the service provider repository instance.
	 *
	 * @return \Illuminate\Foundation\ProviderRepository
	 */
	public function getProviderRepository()
	{
		$manifest = $this['config']['app.stone-boot-manifest'];

		return new ProviderRepository(new Filesystem, $manifest);
	}

    public function getRequestProviderRepository()
    {
		$manifest = $this['config']['app.stone-request-manifest'];

		return new ProviderRepository(new Filesystem, $manifest);
    }

	public function appendDeferredServices(array $services)
	{
		$this->deferredServices = array_merge($this->deferredServices, $services);
	}

    /**
     * snapApp
     *
     * @return void
     */
    public function snapApp()
    {
        $repository = $this->make('snap');
        $this->snapNow();

        $snapBindings = Config::get('stone.web.snap_bindings');
        foreach ($snapBindings as $item) {
            $this[$item]->snapNow($repository);
        }

        Facade::snapStaticNow(Facade::class, $repository);
    }

    /**
     * restoreApp
     * restore App from snap
     *
     * @return void
     */
    public function restoreApp()
    {
        $repository = $this->make('snap');
        $this->restoreSnap();

        $snapBindings = Config::get('stone.web.snap_bindings');
        foreach ($snapBindings as $item) {
            $this[$item]->restoreSnap($repository);
        }

        Facade::restoreStaticSnap(Facade::class, $repository);
    }

    public function runningInConsole()
    {
        return false;
    }

    public function getInstances()
    {
        return $this->instances;
    }
}

