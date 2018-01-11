<?php

namespace Stone\Console\Commands;

use Illuminate\Console\Command;
use Stone\FastCGI\Server;

class StoneServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stone:server {--debug} {--start} {--reload} {--stop}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'A FastCGI server base on swoole with laravel or lumen';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //
        try {
            $config = config('stone.server');
            if ($this->option('debug')) {
                $config['daemonize'] = false;
            }

            $server = new Server($config, app($config['handler']));

            if ($this->option('reload')) {
                if ($server->reload()) {
                    return $this->info('reload [OK]');
                }
            }

            if ($this->option('stop')) {
                if ($server->stop()) {
                    return $this->info('stop [OK]');
                }
            }

            $this->info('start [OK]');
            $server->start();
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }

    }

	protected function getArguments()
	{
		return array();
	}

	/**
	 * Get the console command options.
	 *
	 * @return array
	 */
	protected function getOptions()
	{
		return [
            ['start', 'start', InputOption::VALUE_NONE, 'start the server', null],
            ['reload', 'reload', InputOption::VALUE_NONE, 'reload the server graceful', null],
            ['stop', 'stop', InputOption::VALUE_NONE, 'stop the server', null],
            ['debug', 'debug', InputOption::VALUE_NONE, 'debug mode', null],
        ];
	}

}
