<?php
namespace IvixLabs\SyncDaemon;

use React;

class SyncDaemonClient
{

    private $host;
    private $port;

    /**
     * @var React\Socket\Connection
     */
    private $connection;

    function __construct($host = '127.0.0.1', $port = 1337)
    {
        $this->host = $host;
        $this->port = $port;
    }

    private function init()
    {
        $connection = @stream_socket_client('tcp://' . $this->host . ':' . $this->port);
        if (!$connection) {
            throw new \Exception('No SyncDaemon available.');
        }

        $data = trim(fgets($connection));
        if ($data != 'accept') {
            throw new \Exception('Connection faild. Wrong answer: ' . $data);
        }
        $this->connection = $connection;
    }

    private function checkInit()
    {
        if ($this->connection === null) {
            $this->init();
        }

        if (!$this->connection) {
            throw new \Exception('Client not initialized');
        }
    }

    public function acquire($name, $noWait = false)
    {
        return $this->sendRequest('acquire', $name, $noWait);
    }

    public function release($name)
    {
        return $this->sendRequest('release', $name);
    }

    private function sendRequest($command, $name, $noWait = false)
    {
        $name = md5($name);
        $this->checkInit();
        $result = fwrite($this->connection, "$command $name\n");
        if ($result === false) {
            throw new \Exception('Lost SyncDaemon');
        }
        do {
            $data = trim(fgets($this->connection));
            if ($data == '') {
                throw new \Exception('Lost SyncDaemon');
            }
            if ($noWait && $data == 'wait') {
                return false;
            }
        } while ($data != 'success');

        return true;
    }
}