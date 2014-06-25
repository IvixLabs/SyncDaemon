<?php
namespace IvixLabs\SyncDaemon;

use React;

class SyncDaemonClient
{

    private $host;
    private $port;
    const ACCEPT = 'accept';
    const ACQUIRE = 'acquire';
    const RELEASE = 'release';
    const WAIT = 'wait';
    const SUCCESS = 'success';

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
        stream_set_write_buffer($connection, 0);
        stream_set_read_buffer($connection, 0);
        if (!$connection) {
            throw new \Exception('No SyncDaemon available.');
        }

        $data = trim(fgets($connection));
        if ($data != self::ACCEPT) {
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
        return $this->sendRequest(self::ACQUIRE, $name, $noWait);
    }

    public function release($name)
    {
        return $this->sendRequest(self::RELEASE, $name);
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
            if ($noWait && $data == self::WAIT) {
                return false;
            }
        } while ($data != self::SUCCESS);

        return true;
    }
}