<?php
namespace Stone\FastCGI;

use swoole_server;

class Connection
{
    private $server;
    private $from_id;
    private $fd;

    public function __construct(swoole_server $server, $fd, $from_id)
    {
        $this->server = $server;
        $this->fd = $fd;
        $this->from_id = $from_id;
    }

    public function write($data)
    {
        return $this->server->send($this->fd, $data, $this->from_id);
    }
}
