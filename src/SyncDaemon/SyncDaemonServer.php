<?php
namespace SyncDaemon;


use React;

class SyncDaemonServer
{

    private $host = '127.0.0.1';
    private $port = 1337;
    private $locks = array();
    private $connections;

    function __construct($host = '127.0.0.1', $port = 1337)
    {
        $this->host = $host;
        $this->port = $port;
        $this->connections = new \SplObjectStorage();
    }

    public function start()
    {
        $loop = React\EventLoop\Factory::create();
        $socket = new React\Socket\Server($loop);

        $server = $this;

        $socket->on('connection', function (React\Socket\Connection $connection) use (&$server) {
            $server->connectionEvent($connection);

            $connection->on('data', function ($data) use (&$connection, &$server) {
                list($command, $name) = explode(' ', $data);

                if ($command == 'acquire') {
                    $server->acquireCommand($connection, $name);
                }
                if ($command == 'release') {
                    $server->releaseCommand($connection, $name);
                }
            });

            $connection->on('close', function () use (&$connection, &$server) {
                $server->closeEvent($connection);
            });

            $connection->write("accept\n");
        });

        //debug
        //$loop->addPeriodicTimer(1, function () use (&$server) {
        //    var_dump(count($server->connections) . '-' . count($server->locks));
        //});

        $socket->listen($this->port, $this->host);
        $loop->run();
    }

    private function acquireCommand(React\Socket\Connection $connection, $name)
    {
        if (isset($this->locks[$name])) {
            if (isset($this->connections[$connection][$name])) {
                if ($this->connections[$connection][$name]) {
                    $connection->write("success\n");
                    return;
                } else {
                    $connection->write("wait\n");
                    return;
                }
            } else {
                $this->locks[$name][] = $connection;
                $connectionLocks = $this->connections[$connection];
                $connectionLocks[$name] = false;
                $this->connections[$connection] = $connectionLocks;
                $connection->write("wait\n");
                return;
            }
        } else {
            $this->locks[$name] = new \SplDoublyLinkedList();
            $this->locks[$name][] = $connection;
            $connectionLocks = $this->connections[$connection];
            $connectionLocks[$name] = true;
            $this->connections[$connection] = $connectionLocks;
            $connection->write("success\n");
            return;
        }
    }

    private function releaseCommand(React\Socket\Connection $connection, $name, $sendAnswer = true)
    {
        if (isset($this->locks[$name])) {
            $index = false;
            foreach ($this->locks[$name] as $key => $localConnection) {
                if ($localConnection == $connection) {
                    $index = $key;
                    break;
                }
            }

            if ($index !== false) {

                unset($this->locks[$name][$index]);

                $connectionLocks = $this->connections[$connection];
                if (isset($connectionLocks[$name])) {
                    unset($connectionLocks[$name]);
                    $this->connections[$connection] = $connectionLocks;
                }

                if (count($this->locks[$name]) === 0) {
                    unset($this->locks[$name]);
                } else {
                    if ($index === 0) {
                        $connection->write("success\n");

                        $firstInQueueConnection = $this->locks[$name][0];
                        $connectionLocks = $this->connections[$firstInQueueConnection];
                        $connectionLocks[$name] = true;
                        $this->connections[$firstInQueueConnection] = $connectionLocks;
                        $firstInQueueConnection->write("success\n");
                        return;
                    }
                }
            }
        }

        $connection->write("success\n");
    }

    private function closeEvent(React\Socket\Connection $connection)
    {
        foreach ($this->connections[$connection] as $lockName => $acquired) {
            $this->releaseCommand($connection, $lockName, false);
        }
        unset($this->connections[$connection]);
    }

    private function connectionEvent(React\Socket\Connection $connection)
    {
        $this->connections[$connection] = array();
    }
}