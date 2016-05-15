<?php

namespace Eater\StaticX\Objects;

use Eater\StaticX\IpWrapper;
use Monolog\Logger;
use Eater\StaticX\Object;

class Route implements Object
{
    /**
     * @var Logger
     */
    private $log;

    /**
     * @var IpWrapper
     */
    private $ipWrapper;

    /**
     * @var string
     */
    private $address;
    /**
     * @var string
     */
    private $via;
    /**
     * @var string
     */
    private $dev;

    /**
     * @var boolean
     */
    private $applied = false;

    /**
     * @var boolean
     */
    private $virtuallyApplied = false;

    /**
     * @return string
     */
    public function getAddress()
    {
        return $this->address;
    }

    /**
     * Route constructor.
     * @param Logger $log
     * @param IpWrapper $ipWrapper
     * @param string $address
     * @param string $via
     * @param string $dev
     */
    public function __construct($log, $ipWrapper, $address, $via, $dev)
    {
        $this->log = $log;
        $this->ipWrapper = $ipWrapper;
        $this->address = $address;
        $this->via = $via;
        $this->dev = $dev;
    }

    public function apply($dryRun)
    {
        $routes = $this->ipWrapper->getRoutesLike($this->address, $this->via, $this->dev);

        if (count($routes) === 0 && !$this->virtuallyApplied) {
            $this->log->addInfo("Adding route " . $this->getRouteDescription());
            if (!$dryRun) {
                $this->ipWrapper->addRoute($this->address, $this->via, $this->dev);
            } else {
                $this->virtuallyApplied = true;
            }

            $this->applied = true;
        }
    }

    private function getRouteDescription(){
        return "'{$this->address}' " .
            ($this->via !== null ? "via '{$this->via}' " : '') .
            ($this->dev !== null ? " dev '{$this->dev}'" : '');
    }

    public function revert($dryRun)
    {
        $routes = $this->ipWrapper->getRoutesLike($this->address, $this->via, $this->dev);

        if ((count($routes) > 0 || $this->virtuallyApplied) && $this->applied) {
            $this->log->addInfo("Removing route " . $this->getRouteDescription());
            if (!$dryRun) {
                $this->ipWrapper->removeRoute($this->address, $this->via, $this->dev);
            }
        }
    }

}