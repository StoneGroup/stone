<?php namespace Stone\Foundation;

use Illuminate\Foundation\ProviderRepository as BaseProviderRepository;
use Illuminate\Foundation\Application as LaravelApplication;
use Config;

class ProviderRepository extends BaseProviderRepository
{
    public function load(LaravelApplication $app, array $providers)
    {
        $providers = array_diff($providers, Config::get('app.request-providers'));
        return parent::load($app, $providers);
    }

    public function loadRequest(LaravelApplication $app, array $providers)
    {
		$manifest = $this->loadManifest();

		// First we will load the service manifest, which contains information on all
		// service providers registered with the application and which services it
		// provides. This is used to know which services are "deferred" loaders.
		if ($this->shouldRecompile($manifest, $providers))
		{
			$manifest = $this->compileManifest($app, $providers);
		}

		// If the application is running in the console, we will not lazy load any of
		// the service providers. This is mainly because it's not as necessary for
		// performance and also so any provided Artisan commands get registered.
		if ($app->runningInConsole())
		{
			$manifest['eager'] = $manifest['providers'];
		}

		// Next, we will register events to load the providers for each of the events
		// that it has requested. This allows the service provider to defer itself
		// while still getting automatically loaded when a certain event occurs.
		foreach ($manifest['when'] as $provider => $events)
		{
			$this->registerLoadEvents($app, $provider, $events);
		}

		// We will go ahead and register all of the eagerly loaded providers with the
		// application so their services can be registered with the application as
		// a provided service. Then we will set the deferred service list on it.
		foreach ($manifest['eager'] as $provider)
		{
			$app->register($this->createProvider($app, $provider));
		}

        $app->appendDeferredServices($manifest['deferred']);

    }
}
