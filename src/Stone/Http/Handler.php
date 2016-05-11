<?php namespace Stone\Http;

use Stone\Contracts\RequestHandler;

class Handler implements RequestHandler
{
    private $kernel;

    private $laravel_path;

    public function __construct($laravel_path)
    {
        $this->laravel_path = $laravel_path;
    }

    public function process()
    {
        ob_start();
        $response = $this->kernel->handle(
                $request = \Illuminate\Http\Request::capture()
                );

        $response->send();

        $this->kernel->terminate($request, $response);
        $content = ob_get_contents();
        ob_end_clean();

        return $content;
    }

    public function onWorkerStart()
    {
        require $this->laravel_path.'/bootstrap/autoload.php';
        $app = require_once $this->laravel_path.'/bootstrap/app.php';
        $this->kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    }
}
