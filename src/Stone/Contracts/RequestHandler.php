<?php namespace Stone\Contracts;

interface RequestHandler
{
    public function process();

    public function onWorkerStart();

    /**
     * handleException
     *
     * @param mixed $e
     * @return Response
     */
    public function handleException($e);
}
