<?php
namespace Stone\FastCGI;

use Exception;
use swoole_server;
use Stone\FastCGI\Protocol as FastCGI;
use Stone\FastCGI\Connection as FastCGIConnection;
use Stone\Contracts\RequestHandler as RequestHandler;
use Log;

class Server
{
    private $config;

    private $commitId = null;

    private $handler;

    public function __construct($config, RequestHandler $handler)
    {
        $this->config = $config;
        $this->handler = $handler;
    }

    public function start()
    {
        if ($this->isRunning()) {
            throw new Exception('It seems that the server is running.');
        }

        $config = $this->config;

        if ($config['domain'][0] == '/') {
            $type = SWOOLE_SOCK_UNIX_STREAM;
        } else {
            $type = SWOOLE_SOCK_TCP;
        }

        $serv = new swoole_server($config['domain'], $config['port'], SWOOLE_PROCESS, $type);
        $options = array(
            'worker_num' => $config['worker_num'],
            'task_worker_num' => $config['task_worker_num'],
            'daemonize' => $config['daemonize'],
            'open_eof_check' => $config['open_eof_check'],
            'package_eof' => $config['package_eof'],
            'log_file' => $config['log_file'],
        );

        if (!empty($config['user'])) {
            $options['user'] = $config['user'];
        }

        if (!empty($config['group'])) {
            $options['group'] = $config['group'];
        }

        $serv->set($options);

        $serv->on('Start', [$this, 'onStart']);
        $serv->on('Connect', [$this, 'onConnect']);
        $serv->on('Receive', [$this, 'onReceive']);
        $serv->on('Task', [$this, 'onTask']);
        $serv->on('Finish', [$this, 'onFinish']);
        $serv->on('WorkerStart', [$this, 'onWorkerStart']);
        $serv->on('ManagerStart', [$this, 'onManagerStart']);
        $serv->on('Close', [$this, 'onClose']);

        //$this->commitId = $this->getCommitId();

        $serv->start();
    }

    public function stop()
    {
        if (!$this->isRunning()) {
            throw new Exception('Server is not running');
        }

        $pid = $this->getMainPid();
        posix_kill($pid, SIGTERM);
        unlink($this->config['pid']);

        return true;
    }

    public function reload()
    {
        if (!$this->isRunning()) {
            throw new Exception('Server is not running');
        }

        $pid = $this->getMainPid();
        posix_kill($pid, SIGUSR1);

        return true;
    }

    public function isRunning()
    {
        $pid_file = $this->config['pid'];

        if (!file_exists($pid_file)) {
            return false;
        }

        $main_pid = $this->getMainPid();
        if (!posix_kill($main_pid, 0)) {
            unlink($pid_file);
            return false;
        }

        return true;
    }

    public function writePid()
    {
        $pid = getmypid();
        $res = file_put_contents($this->config['pid'], $pid);

        if ($res === false) {
            throw new Exception('Write pid file failure, Maybe you need to run with super user.');
        }
    }

    public function getMainPid()
    {
        return file_get_contents($this->config['pid']);
    }

    public function onStart(swoole_server $server)
    {
        swoole_set_process_name($this->config['process_name']);
        $this->writePid();
    }

    public function onConnect()
    {
    }

    public function onTask(swoole_server $serv, $task_id, $from_id, $data)
    {
    }

    public function onFinish()
    {

    }

    public function onManagerStart(swoole_server $server)
    {
        swoole_set_process_name($this->config['process_name'] . ':manager');
        if ($this->config['domain'][0] == '/') {
            chown($this->config['domain'], $this->config['user']);
        }
    }


    public function onWorkerStart(swoole_server $server, $worker_id)
    {
        opcache_reset();

        $this->handler->onWorkerStart();

        if ($worker_id >= $server->setting['worker_num']) {
            swoole_set_process_name($this->config['process_name'] . ':tasker');
            // $this->liveCheck($server);
        } else {
            swoole_set_process_name($this->config['process_name'] . ':worker');
        }
    }

    public function onReceive(swoole_server $server, $fd, $from_id, $data)
    {
        $fastCGI = new FastCGI(new FastCGIConnection($server, $fd, $from_id));
        $requestData = $fastCGI->readFromString($data);
        $request = current($requestData);
        $_SERVER = $request['params'];
        $_SERVER['SCRIPT_FILENAME'] = 'index.php';
        $_COOKIE = $_POST = $_GET = $REQUEST = [];

        if (!empty($_SERVER['QUERY_STRING'])) {
            parse_str($_SERVER['QUERY_STRING'], $_GET);
        }

        if (!empty($request['rawPost'])) {
            $_SERVER['HTTP_RAW_POST'] = $request['rawPost'];
            parse_str($request['rawPost'], $_POST);
        }

        if (!empty($_SERVER['HTTP_COOKIE'])) {
            $cookies = explode('; ', $_SERVER['HTTP_COOKIE']);
            foreach ($cookies as $item) {
                $item = explode('=', $item);
                if (count($item) === 2) {
                    $_COOKIE[$item[0]] = urldecode($item[1]);
                }
            }
        }

        $_REQUEST = array_merge($_GET, $_POST);

        try {
            ob_start();
            $response = $this->handler->process();
            $output = ob_get_contents();
            ob_end_clean();
            $content = strval($response);

            if (!empty($output)) {
                $content .= '<pre>' . $output . '</pre>';
            }

        } catch (Exception $e) {
            $content = strval($this->handler->handleException($e));
        }

        $fastCGI->sendDataToClient(1, $content);
        $server->close($fd);

        return;
    }

    public function onClose()
    {
    }

    public function liveCheck(swoole_server $server)
    {
        $base_path = base_path();
        $output = $ret = null;
        $cmd = "cd $base_path && git log|head -1|awk '{print \$NF}'";

        while (true) {
            $commitId = exec($cmd, $output, $ret);
            $current_commitId = $this->getCommitId();

            if ($commitId != $current_commitId) {
                file_put_contents($this->config['live_check_file'], $commitId);
            }

            sleep(10);
        }
    }

    public function getCommitId()
    {
        return file_get_contents($this->config['live_check_file']);
    }
}

