<?php namespace Stone;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Facade;
use Stone\Snap\Runkit;
use Stone\Snap\Repository;
use Config;

class StoneServiceProvider extends ServiceProvider
{

    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/stone.php', 'stone.web'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../../config/stone.php', 'stone.server'
        );

        $this->app->singleton('snap', function() {return new Repository();});

        $this->injectFacade();
    }

    public function injectFacade()
    {
        if (!function_exists('runkit_method_redefine')) {
            return;
        }

        Runkit::addStaticSnapMethods('\Illuminate\Support\Facades\Facade');
    }

}
