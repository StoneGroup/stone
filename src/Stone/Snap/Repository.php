<?php namespace Stone\Snap;

class Repository
{
    private $data = [];

    /**
     * set
     * keep data to repository
     *
     * @param string $key
     * @param mixed $data
     * @return void
     */
    public function set($key, $data)
    {
        $this->data[$key] = $data;
    }

    /**
     * get
     * fetch data from repository
     *
     * @param string $key
     * @return mixed
     */
    public function get($key)
    {
        if (isset($this->data[$key])) {
            return $this->data[$key];
        }

        return [];
    }
}
