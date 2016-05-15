<?php
/**
 * Created by PhpStorm.
 * User: eater
 * Date: 5/14/16
 * Time: 7:00 PM
 */

namespace Eater\StaticX;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Kernel
{
    /**
     * @var Logger
     */
    private $log;

    /**
     * @var \stdClass
     */
    private $config;

    /**
     * @var string|null
     */
    private $configPath = '/etc/static-x/config.yml';

    /**
     * @var string|null
     */
    private $interface;

    /**
     * @var InterfaceKernel[]
     */
    private $interfaceKernels = [];

    /**
     * @var boolean
     */
    private $keepOnExit = false;

    /**
     * @var array
     */
    private $cliConfig = [];

    /**
     * @var IpWrapper
     */
    private $ipWrapper = null;

    /**
     * @var boolean
     */
    private $dryRun = false;

    /**
     * @var boolean
     */
    private $gotStopSignal = false;

    /**
     * @var boolean
     */
    private $flushOnBoot = false;

    /**
     * @var InterfaceKernel[]
     */
    private $interfaceKernelsToRevert = [];

    /**
     * @var InterfaceKernel[]
     */
    private $interfaceKernelsToFlush = [];

    public function boot()
    {
        $this->log = new Logger('static-x');
        $this->log->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

        $this->parseOptions();
        $this->loadConfig();

        if ($this->dryRun) {
            $this->log->addInfo("Running in noop mode");
        }

        $this->initializeInterfaces();
        $success = $this->bootInterfaces($this->interfaceKernels);

        if (!$success) {
            $this->log->addCritical('Static-X failed to boot, exiting.');
            exit(1);
        }

        $this->attachSignalHandlers();
        $this->startMainLoop();
    }

    private function startMainLoop() {
        do {

            while ($interfaceKernel = array_shift($this->interfaceKernelsToFlush)) {
                $interfaceKernel->flush($this->dryRun);
            }

            while ($interfaceKernel = array_shift($this->interfaceKernelsToRevert)) {
                $interfaceKernel->revert($this->dryRun);
            }

            $this->apply();

            $success = pcntl_signal_dispatch();
            if (!$success) {
                $this->log->addCritical("Couldn't dispatch signal traps");
            }

            usleep(500000);

        } while (!$this->gotStopSignal);

        $this->log->addInfo('I was the only one');
    }

    /**
     * @return IpWrapper
     */
    private function getIpWrapper()
    {
        if ($this->ipWrapper === null) {
            $this->ipWrapper = new IpWrapper($this->log);
        }

        return $this->ipWrapper;
    }

    private function initializeInterfaces() {
        if ($this->interface !== null) {
            $this->initializeInterface($this->interface);
        }

        if (!isset($this->config['interfaces'])) {
            return;
        }

        foreach (array_keys($this->config['interfaces']) as $interface) {
            $this->initializeInterface($interface);
        }
    }

    private function initializeInterface($interface)
    {
        $config = [];

        if (isset($this->config['interfaces'][$interface])) {
            $config = $this->config['interfaces'][$interface];
        }

        if ($this->interface !== null) {
            $config = $this->cliConfig;
        }

        $this->interfaceKernels[$interface] = new InterfaceKernel($this->log, $this->getIpWrapper(), $interface, $config, $this->flushOnBoot);
    }

    /**
     * @param InterfaceKernel[] $interfaces
     * @return bool
     */
    private function bootInterfaces($interfaces)
    {
        $hasErrors = false;
        foreach ($interfaces as $kernel) {
            $kernelHasErrors = !$kernel->boot();

            if ($kernelHasErrors) {
                $hasErrors = true;
            }
        }

        return !$hasErrors;
    }

    private function attachSignalHandlers()
    {
        $success = pcntl_signal(SIGHUP, function () {
            $this->log->addInfo('Got HUP signal reloading');
            $this->reload();
        });

        if (!$success) {
            $this->log->addCritical("Attaching SIGHUP trap failed");
        }
        
        pcntl_signal(SIGINT, function () {
            $this->log->addInfo('Got INT signal starting shutdown');
            $this->shutdown();
        });

        if (!$success) {
            $this->log->addCritical("Attaching SIGINT trap failed");
        }

        pcntl_signal(SIGTERM, function () {
            $this->log->addInfo('Got TERM signal starting shutdown');
            $this->shutdown();
        });

        if (!$success) {
            $this->log->addCritical("Attaching SIGTERM trap failed");
        }
    }

    private function apply()
    {

        /**
         * @var InterfaceKernel[] $interfaceKernels
         */
        $interfaceKernels = array_merge([], $this->interfaceKernels);

        foreach ($interfaceKernels as $interface => $kernel) {
            $success = $kernel->apply($this->dryRun);

            if (!$success) {
                $this->log->addCritical("Management for '{$interface}' crashed, unloading from static-x");
                unset($this->interfaceKernels[$interface]);
            }
        }

        if (count($this->interfaceKernels) === 0) {
            $this->log->addCritical("No interfaces to manage anymore. exiting");
            exit(1);
        }
    }

    private function reload() {
        if ($this->configPath === null) {
            $this->log->addInfo("Reloading with --no-config is useless");
            return;
        }

        $config = $this->getConfig(false);

        if ($config === false) {
            $this->log->addCritical("Reloading failed, nothing done");
            return;
        }

        $oldInterfaces = array_keys($this->interfaceKernels);
        $newInterfaces = array_keys($config['interfaces']);

        $interfacesToRemove = array_diff($oldInterfaces, $newInterfaces);
        $interfacesToAdd = array_diff($newInterfaces, $oldInterfaces);
        $interfacesToReload = array_intersect($newInterfaces, $oldInterfaces);

        $newKernels = [];
        foreach ($interfacesToAdd as $interface) {
            $newKernels[] = new InterfaceKernel($this->log, $this->ipWrapper, $interface, $config['interfaces'][$interface], $this->flushOnBoot);
        }
        
        $success = $this->bootInterfaces($newKernels);
        
        if (!$success) {
            $this->log->addCritical("Reloading failed, nothing done");
        }
        
        foreach ($newKernels as $kernel) {
            $this->interfaceKernels[$kernel->getName()] = $kernel;
        }

        foreach ($interfacesToRemove as $interface) {
            $this->scheduleRevert($this->interfaceKernels[$interface]);
            unset($this->interfaceKernels[$interface]);
        }

        foreach ($interfacesToReload as $interface) {
            $kernel = $this->interfaceKernels[$interface];
            $kernel->reload($config['interfaces'][$interface]);
        }
    }

    private function scheduleRevert($interfaceKernel) {
        if ($this->keepOnExit) {
            return;
        }

        $this->interfaceKernelsToRevert[] = $interfaceKernel;
    }

    private function shutdown() {
        $this->gotStopSignal = true;

        if ($this->keepOnExit) {
            return;
        }

        foreach ($this->interfaceKernels as $interface => $kernel) {
            $kernel->revert($this->dryRun);
        }
    }
    
    private function parseOptions()
    {
        $options = getopt(
            "c:i:l:Xa:r:cA:nf",
            [
                'noop',
                'dryrun',
                'no-config',
                'flush',
                'keep-on-exit',
                'default',
                'log:',
                'config:',
                'interface:',
                'ip:',
                'secondary-ip:',
                'route:',
            ]
        );

        foreach ($options as $key => $value) {
            $this->parseOption($key, $value);
        }
    }

    private function parseOption($option, $value)
    {
        switch ($option) {
            case 'c':
            case 'config':
                $this->configPath = $value;
                break;
            case 'X':
            case 'no-config':
                $this->configPath = null;
                break;
            case 'i':
            case 'interface':
                $this->interface = $value;
                break;
            case 'l':
            case 'log':
                $this->addLogs((array) $value);
                break;
            case 'keep-on-exit':
                $this->keepOnExit = true;
                break;
            case 'a':
            case 'ip':
                $this->cliConfig['primary-ip'] = $value;
                break;
            case 'A':
            case 'secondary-ip':
                $this->cliConfig['secondary-ips'] = (array) $value;
                break;
            case 'r':
            case 'router':
                $this->cliConfig['route'] = $value;
                break;
            case 'd':
            case 'default':
                $this->cliConfig['default'] = true;
                break;
            case 'n':
            case 'noop':
            case 'dryrun':
                $this->dryRun = true;
                break;
            case 'f':
            case 'flush':
                $this->flushOnBoot = true;
                break;

        }
    }

    private function addLogs($logs) {
        foreach ($logs as $log) {
            $this->log->pushHandler(new StreamHandler($log, Logger::INFO));
        }
    }

    private function loadConfig()
    {
        if ($this->configPath === null) {
            return;
        }

        $yaml = $this->getConfig(true);

        $this->config = $yaml;
    }

    /**
     * @param boolean $exitOnFailure
     * @return array
     */
    private function getConfig($exitOnFailure) {
        if (!is_readable($this->configPath)) {
            $this->log->addCritical("Config '{$this->configPath}' is not readable. use --no-config or make it readable");

            if ($exitOnFailure) {
                exit(1);
            }

            return false;
        }

        $configYaml = file_get_contents($this->configPath);

        if ($configYaml === false) {
            $this->log->addCritical("Config '{$this->configPath}' couldn't be read.");

            if ($exitOnFailure) {
                exit(1);
            }

            return false;
        }

        $yaml = \Spyc::YAMLLoadString($configYaml);

        return $yaml;
    }
}