<?php

namespace Psocksd\Command;

use Psocksd\App;
use Socks\Client;
use ConnectionManager\ConnectionManager;
use ConnectionManager\ConnectionManagerInterface;
use ConnectionManager\Extra\ConnectionManagerReject;
use \UnexpectedValueException;
use \InvalidArgumentException;
use \Exception;

class Via implements CommandInterface
{
    protected $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function getHelp()
    {
        return 'forward all connections via next SOCKS server';
    }

    public function run($args)
    {
        if (count($args) === 1 && $args[0] === 'list') {
            $this->runList();
        } else if (count($args) === 2 && $args[0] === 'default') {
            $this->runSetDefault($args[1]);
        } else if (count($args) === 2 && $args[0] === 'reject') {
            $this->runAdd($args[1], 'reject', -1);
        } else if ((count($args) === 3 || count($args) === 4) && $args[0] === 'add') {
            $this->runAdd($args[1], $args[2], isset($args[3]) ? $args[3] : 0);
        } else if (count($args) === 2 && $args[0] === 'remove') {
            $this->runRemove($args[1]);
        } else {
            echo 'error: invalid command arguments ()' . PHP_EOL;
        }
    }

    public function runList()
    {
        $cm = $this->app->getConnectionManager();

        $lengths = array(
            'id' => 3,
            'host' => 5,
            'port' => 5,
            'priority' => 5
        );

        $list = array();
        foreach ($cm->getConnectionManagerEntries() as $id => $entry) {
            $list [$id]= $entry;

            $entry['id'] = $id;
            foreach ($lengths as $key => &$value) {
                $l = mb_strlen($entry[$key], 'utf-8');
                if ($l > $value) {
                    $value = $l;
                }
            }
        }

        echo $this->pad('Id:', $lengths['id']) . ' ' .
             $this->pad('Host:', $lengths['host']) . ' ' .
             $this->pad('Port:', $lengths['port']) . ' ' .
             $this->pad('Prio:', $lengths['priority']) . ' ' .
             'Target:' . PHP_EOL;
        foreach ($list as $id => $entry) {
            echo $this->pad($id, $lengths['id']) . ' ' .
                 $this->pad($entry['host'], $lengths['host']) . ' ' .
                 $this->pad($entry['port'], $lengths['port']) . ' ' .
                 $this->pad($entry['priority'], $lengths['priority']) . ' ' .
                 $this->dumpConnectionManager($entry['connectionManager']) . PHP_EOL;
        }
    }

    public function runRemove($id)
    {
        $this->app->getConnectionManager()->removeConnectionManagerEntry($id);
    }

    public function runSetDefault($socket)
    {
        try {
            $via = $this->createConnectionManager($socket);
        }
        catch (Exception $e) {
            echo 'error: invalid target: ' . $e->getMessage() . PHP_EOL;
            return;
        }

        // remove all CMs with PRIORITY_DEFAULT
        $cm = $this->app->getConnectionManager();
        foreach ($cm->getConnectionManagerEntries() as $id => $entry) {
            if ($entry['priority'] == App::PRIORITY_DEFAULT) {
                $cm->removeConnectionManagerEntry($id);
            }
        }

        $cm->addConnectionManagerFor($via, '*', '*', App::PRIORITY_DEFAULT);
    }

    public function runAdd($target, $socket, $priority)
    {
        try {
            $via = $this->createConnectionManager($socket);
        }
        catch (Exception $e) {
            echo 'error: invalid target: ' . $e->getMessage() . PHP_EOL;
            return;
        }

        try {
            $priority = $this->coercePriority($priority);
        }
        catch (Exception $e) {
            echo 'error: invalid priority: ' . $e->getMessage() . PHP_EOL;
            return;
        }

        // TODO: support IPv6 addresses
        $parts = explode(':', $target, 2);
        $host = $parts[0];
        $port = isset($parts[1]) ? $parts[1] : '*';

        $this->app->getConnectionManager()->addConnectionManagerFor($via, $host, $port, $priority);
    }

    protected function createConnectionManager($socket)
    {
        if ($socket === 'reject') {
            echo 'reject' . PHP_EOL;
            return new ConnectionManagerReject();
        }
        $direct = new ConnectionManager($this->app->getLoop(), $this->app->getResolver());
        if ($socket === 'none') {
            $via = $direct;

            echo 'use direct connection to target' . PHP_EOL;
        } else {
            $parsed = $this->app->parseSocksSocket($socket);

            // TODO: remove hack
            // resolver can not resolve 'localhost' ATM
            if ($parsed['host'] === 'localhost') {
                $parsed['host'] = '127.0.0.1';
            }

            $via = new Client($this->app->getLoop(), $direct, $this->app->getResolver(), $parsed['host'], $parsed['port']);
            if (isset($parsed['protocolVersion'])) {
                try {
                    $via->setProtocolVersion($parsed['protocolVersion']);
                }
                catch (Exception $e) {
                    throw new Exception('invalid protocol version: ' . $e->getMessage());
                }
            }
            if (isset($parsed['user']) || isset($parsed['pass'])) {
                $parsed += array('user' =>'', 'pass' => '');
                try {
                    $via->setAuth($parsed['user'], $parsed['pass']);
                }
                catch (Exception $e) {
                    throw new Exception('invalid authentication info: ' . $e->getMessage());
                }
            }

            echo 'use '.$this->app->reverseSocksSocket($parsed) . ' as next hop';

            try {
                $via->setResolveLocal(false);
                echo ' (resolve remotely)';
            }
            catch (UnexpectedValueException $ignore) {
                // ignore in case it's not allowed (SOCKS4 client)
                echo ' (resolve locally)';
            }

            echo PHP_EOL;
        }
        return $via;
    }

    protected function coercePriority($priority)
    {
        $ret = filter_var($priority, FILTER_VALIDATE_FLOAT);
        if ($ret === false) {
            throw new InvalidArgumentException('Invalid priority given');
        }
        return $ret;
    }

    private function pad($str, $len)
    {
        return $str . str_repeat(' ', $len - mb_strlen($str, 'utf-8'));
    }

    protected function dumpConnectionManager(ConnectionManagerInterface $connectionManager)
    {
        if ($connectionManager instanceof Client) {

        }
        return get_class($connectionManager) . '()';
    }
}
