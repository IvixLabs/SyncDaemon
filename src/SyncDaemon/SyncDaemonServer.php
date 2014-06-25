<?php
namespace IvixLabs\SyncDaemon;

use React;

class SyncDaemonServer
{
    const ACCEPT = "accept\n";
    const ACQUIRE = 'acquire';
    const RELEASE = 'release';

    private $host = '127.0.0.1';
    private $port = 1337;
    private $locks = array();
    private $connections;

    const SUCCESS = "success\n";
    const WAIT = "wait\n";

    const CONNECTION = 'connection';
    const DATA = 'data';

    const CLOSE = 'close';

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

        $socket->on(self::CONNECTION, function (React\Socket\Connection $connection) use (&$server) {
            $server->connectionEvent($connection);

            $connection->on(SyncDaemonServer::DATA, function ($data) use (&$connection, &$server) {
                list($command, $name) = explode(' ', $data);

                if ($command == SyncDaemonServer::ACQUIRE) {
                    $server->acquireCommand($connection, $name);
                }
                if ($command == SyncDaemonServer::RELEASE) {
                    $server->releaseCommand($connection, $name);
                }
            });

            $connection->on(SyncDaemonServer::CLOSE, function () use (&$connection, &$server) {
                $server->closeEvent($connection);
            });

            $connection->write(SyncDaemonServer::ACCEPT);
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
                    $connection->write(self::SUCCESS);
                    return;
                } else {
                    $connection->write(self::WAIT);
                    return;
                }
            } else {
                $this->locks[$name][] = $connection;
                $connectionLocks = $this->connections[$connection];
                $connectionLocks[$name] = false;
                $this->connections[$connection] = $connectionLocks;
                $connection->write(self::WAIT);
                return;
            }
        } else {
            $this->locks[$name] = new \SplDoublyLinkedList();
            $this->locks[$name][] = $connection;
            $connectionLocks = $this->connections[$connection];
            $connectionLocks[$name] = true;
            $this->connections[$connection] = $connectionLocks;
            $connection->write(self::SUCCESS);
            return;
        }
    }

    private function releaseCommand(React\Socket\Connection $connection, $name, $sendAnswer = true)
    {
        if (isset($this->locks[$name])) {
            $connection = $this->locks[$name]->shift();
            $connectionLocks = $this->connections[$connection];
            if (isset($connectionLocks[$name])) {
                unset($connectionLocks[$name]);
                $this->connections[$connection] = $connectionLocks;
            }

            if (count($this->locks[$name]) === 0) {
                unset($this->locks[$name]);
            } else {
                $connection->write(self::SUCCESS);

                /** @var $firstInQueueConnection React\Socket\Connection */
                while (($firstInQueueConnection = $this->locks[$name][0]) !== null) {
                    if (isset($this->connections[$firstInQueueConnection])) {
                        $connectionLocks = $this->connections[$firstInQueueConnection];
                        $connectionLocks[$name] = true;
                        $this->connections[$firstInQueueConnection] = $connectionLocks;
                        $firstInQueueConnection->write(self::SUCCESS);
                        return;
                    } else {
                        $this->locks[$name]->shift();
                    }
                }
                return;
            }
        }

        $connection->write(self::SUCCESS);
    }

    private function closeEvent(React\Socket\Connection $connection)
    {
        foreach ($this->connections[$connection] as $lockName => $acquired) {
            foreach ($this->locks[$lockName] as $index => $localConn) {
                if ($localConn == $connection) {
                    if ($index == 0) {
                        $this->releaseCommand($connection, $lockName, false);
                    } else {
                        unset($this->locks[$lockName][$index]);
                    }
                }
            }
        }
        unset($this->connections[$connection]);
    }

    private function connectionEvent(React\Socket\Connection $connection)
    {
        $this->connections[$connection] = array();
    }
}