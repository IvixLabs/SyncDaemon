<?php
namespace IvixLabs\SyncDaemon;


class Lock
{
    /**
     * @var SyncDaemonClient
     */
    static private $syncDaemonClient;

    private $name;

    private function __construct($name)
    {
        $this->name = $name;
    }

    static public function setSycDaemonClient(SyncDaemonClient $client)
    {
        if (self::$syncDaemonClient !== null) {
            throw new \Exception('Client already initialized');
        }

        self::$syncDaemonClient = $client;
    }

    static public function acquire($name, $noWait = false)
    {
        self::checkInit();
        if (self::$syncDaemonClient->acquire($name, $noWait)) {
            return new Lock($name);
        }
        return false;
    }

    static public function release($name)
    {
        self::checkInit();
        return self::$syncDaemonClient->release($name);
    }

    static private function checkInit()
    {
        if (self::$syncDaemonClient === null) {
            throw new \Exception('SyncDaemonClient not initialized');
        }
    }

    /**
     * Release lock object
     * @return bool
     */
    public function free()
    {
        return self::release($this->name);
    }
} 