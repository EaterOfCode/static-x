<?php

namespace Eater\StaticX;


use Eater\StaticX\Objects\Address;
use Eater\StaticX\Objects\DefaultRoute;
use Eater\StaticX\Objects\Route;
use Monolog\Logger;

class InterfaceKernel
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
    private $name;

    /**
     * @var array
     */
    private $config;

    /**
     * @var Object[]
     */
    private $objects = [];

    /**
     * @var Object[]
     */
    private $objectsToRevert = [];

    /**
     * @var boolean|null
     */
    private $hadLinkPreviously = null;

    private $flashOnBoot = false;

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * InterfaceKernel constructor
     * @param Logger $log
     * @param IpWrapper $ipWrapper
     * @param string $name
     * @param array $config
     */
    public function __construct($log, $ipWrapper, $name, $config, $flashOnBoot)
    {
        $this->log = $log;
        $this->ipWrapper = $ipWrapper;
        $this->name = $name;
        $this->config = $config;
        $this->flashOnBoot = $flashOnBoot;
    }

    /**
     * @return boolean
     */
    public function boot()
    {
        if (!$this->validateConfig($this->config)) {
            return false;
        }

        $this->objects[] = new Address($this->log, $this->ipWrapper, $this->config['primary-ip'], $this->name);

        foreach ($this->config['secondary-ips'] as $ip) {
            $this->objects[] = new Address($this->log, $this->ipWrapper, $ip, $this->name);
        }

        $this->objects[] = new Route($this->log, $this->ipWrapper, $this->config['primary-ip'], null, $this->name);

        if (isset($this->config['default-route'])) {
            $this->objects[] = new DefaultRoute($this->log, $this->ipWrapper, $this->config['default-route']);
        }

        return true;
    }

    /**
     * @return boolean
     */
    public function isHotpluggable() {
        return isset($this->config['hotplug']) && $this->config['hotplug'];
    }

    /**
     * @param boolean $dryRun
     * @return boolean
     */
    public function apply($dryRun)
    {
        $hasLink = $this->ipWrapper->hasLink($this->name);

        if ($hasLink !== $this->hadLinkPreviously) {
            if (!$hasLink && !$this->isHotpluggable()) {
                $this->log->addCritical("Missing link '{$this->name}'");
                return false;
            }

            $this->log->addInfo(($hasLink ? "Found" : "Missing") . " link '{$this->name}'");
            $this->hadLinkPreviously = $hasLink;

            if ($hasLink) {
                $this->log->addInfo("Flushing '{$this->name}'");
                if (!$dryRun) {
                    $this->ipWrapper->flush($this->name);
                }
            }
        }

        if (!$hasLink) {
            return true;
        }

        while ($object = array_shift($this->objectsToRevert)) {
            $object->revert($dryRun);
        }

        /**
         * @var Object[]
         */
        $appliedObjects = [];
        try {
            foreach ($this->objects as $object) {
                $object->apply($dryRun);

                $appliedObjects[] = $object;
            }
        } catch (\Exception $e) {
            $this->log->addCritical("Something went wrong for '{$this->name}'. reverting actions");
            foreach (array_reverse($appliedObjects) as $object) {
                /**
                 * @var Object $object
                 */
                $object->revert($dryRun);
            }

            return false;
        }

        return true;
    }

    /**
     * @param boolean $dryRun
     */
    public function revert($dryRun)
    {
        if ($this->isHotpluggable() && !$this->ipWrapper->hasLink($this->name)) {
            return;
        }

        foreach (array_reverse($this->objects) as $object) {
            /**
             * @var Object $object
             */
            $object->revert($dryRun);
        }
    }

    public function shutdown() {
    }

    public function reload($config) {
        $success = $this->validateConfig($config);

        if (!$success) {
            $this->log->addCritical("Reloading config for '{$this->name}' failed, continuing on old config");
            return;
        }

        $cachedObjects = array_merge([], $this->objects);
        $oldRoute = isset($this->config['default-route']) ? $this->config['default-route'] : false;
        $newRoute = isset($config['default-route']) ? $config['default-route'] : false;

        if ($newRoute !== $oldRoute) {
            foreach ($cachedObjects as $i => $object) {
                if ($object instanceof DefaultRoute) {
                    $this->scheduleRevert($object);
                    unset($this->objects[$i]);
                }
            }

            if ($newRoute) {
                $this->objects[] = new DefaultRoute($this->log, $this->ipWrapper, $config['default-route']);
            }
        }

        if ($config['primary-ip'] !== $this->config['primary-ip']) {
            foreach ($cachedObjects as $i => $object) {
                if ($object instanceof Route && $object->getAddress() === $this->config['primary-ip']) {
                    $this->scheduleRevert($object);
                    unset($this->objects[$i]);
                }
            }

            $this->objects[] = new Route($this->log, $this->ipWrapper, $config['primary-ip'], null, $this->name);
        }
        
        $currentIps = array_merge([$this->config['primary-ip']], $this->config['secondary-ips']);
        $newIps     = array_merge([$config['primary-ip']], $config['secondary-ips']);

        $toRemove = array_diff($currentIps, $newIps);
        $toAdd    = array_diff($newIps, $currentIps);

        foreach ($cachedObjects as $i => $object) {
            if ($object instanceof Address && in_array($object->getAddress(), $toRemove)) {
                $this->scheduleRevert($object);
                unset($this->objects[$i]);
            }
        }

        foreach ($toAdd as $address) {
            $this->objects[] = new Address($this->log, $this->ipWrapper, $address, $this->name);
        }

        $this->log->addInfo("Reloaded config for '{$this->name}', next cycle changes will be applied");
        $this->config = $config;
    }

    public function scheduleRevert($object) {
        if ($this->hadLinkPreviously) {
            $this->objectsToRevert[] = $object;
        }
    }

    public function validateConfig($config)
    {
        $hasErrors = false;
        $route = isset($config['default-route']) ? $config['default-route'] : false;

        if ($route !== false && !is_string($route)) {
            $this->log->addCritical("Route for '{$this->name}' isn't a string or not defined");
            $hasErrors = true;
        }

        $util = new Util();

        if (!isset($config['primary-ip'])) {
            $this->log->addCritical("No primary ip set for '{$this->name}'");
            $hasErrors = true;
        } elseif (!is_string($config['primary-ip'])) {
            $this->log->addCritical("Primary ip for '{$this->name}' isn't a string");
            $hasErrors = true;
        } elseif (!$util->validateCidr($config['primary-ip'])) {
            $this->log->addCritical("Primary ip '{$config["primary-ip"]}' for '{$this->name}' is invalid");
        }

        if (isset($config['secondary-ips'])) {
            if (!is_array($config['secondary-ips'])) {
                $this->log->addCritical("Secondary ips for '{$this->name}' is not an array");
                $hasErrors = true;
            } else {
                foreach ($config['secondary-ips'] as $index => $ip) {
                    if (!is_string($ip)) {
                        $hasErrors = true;
                        $this->log->addCritical("Secondary ip #{$index} for '{$this->name}' isn't a string");
                        continue;
                    }

                    if (!$util->validateCidr($ip)) {
                        $hasErrors = true;
                        $this->log->addCritical("Secondary ip '{$ip}' for '{$this->name}' is invalid");
                    }
                }
            }
        }

        return !$hasErrors;
    }
}