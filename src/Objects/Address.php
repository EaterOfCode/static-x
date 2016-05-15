<?php

namespace Eater\StaticX\Objects;


use Eater\StaticX\IpWrapper;
use Eater\StaticX\Object;
use Monolog\Logger;

class Address implements Object
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
     * Address constructor.
     * @param Logger $log
     * @param IpWrapper $ipWrapper
     * @param string $address
     * @param string $dev
     */
    public function __construct($log, $ipWrapper, $address, $dev) {
        $this->log = $log;
        $this->ipWrapper = $ipWrapper;
        $this->address = $address;
        $this->dev = $dev;
    }

    public function apply($dryRun)
    {
        $addresses = $this->ipWrapper->getAddresses($this->dev);

        if (!in_array($this->address, $addresses) && !$this->virtuallyApplied) {
            $this->log->addInfo("Adding address '{$this->address}' to '{$this->dev}'");
            if (!$dryRun) {
                $this->ipWrapper->addAddress($this->address, $this->dev);
            } else {
                $this->virtuallyApplied = true;
            }

            $this->applied = true;
        }
    }

    public function revert($dryRun)
    {
        $addresses = $this->ipWrapper->getAddresses($this->dev);

        if ((in_array($this->address, $addresses) || $this->virtuallyApplied) && $this->applied) {
            $this->log->addInfo("Removing address '{$this->address}' from '{$this->dev}'");
            if (!$dryRun) {
                $this->ipWrapper->removeAddress($this->address, $this->dev);
            }
        }
    }


}