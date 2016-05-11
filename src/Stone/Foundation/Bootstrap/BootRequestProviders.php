<?php

namespace Stone\Foundation\Bootstrap;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Foundation\AliasLoader;
use Config;

class BootRequestProviders
{
    /**
     * Bootstrap the given application.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public function bootstrap(Application $app)
    {
        $requestProviders = (array)Config::get('stone.web.providers');
        $providers = Config::get('app.providers');

        Config::set('app.providers', array_diff($providers, $requestProviders));

        foreach ($requestProviders as $provider) {
            $app->register($provider);
        }
    }
}
