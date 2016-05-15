<?php
/**
 * Created by PhpStorm.
 * User: eater
 * Date: 5/14/16
 * Time: 8:55 PM
 */

namespace Eater\StaticX;


use Monolog\Logger;

class IpWrapper
{
    /**
     * @var Logger
     */
    private $log;

    /**
     * IpWrapper constructor.
     * @param Logger $log
     */
    public function __construct($log)
    {
        $this->log = $log;
    }

    /**
     * @param string $dev
     * @return boolean
     */
    public function hasLink($dev)
    {
        return $this->checkExecute('ip link show ' . escapeshellarg($dev) . ' 2>&1');
    }

    /**
     * @param string $dev
     * @return array
     * @throws \Exception
     */
    public function getAddresses($dev)
    {
        return $this->execute("ip addr list " . escapeshellarg($dev) . " | grep 'inet' | awk '{print $2}'");
    }

    /**
     * @param string $address
     * @param string $via
     * @param string $dev
     * @return array
     * @throws \Exception
     */
    public function getRoutesLike($address, $via, $dev) {

        $command = "";
        if ($via !== null) {
            $command = 'via ' . escapeshellarg($via);
        } elseif ($dev) {
            $command = 'dev ' . escapeshellarg($dev);
        }

        $data = $this->execute('ip route show ' . $command);

        $routes = [];
        foreach ($data as $route) {
            $routeItem = $this->parseRouteLine($route);
            if ($routeItem === false) {
                continue;
            }

            if (!$this->compareAddress($routeItem['address'], $address)) {
                continue;
            }

            if ($via !== null && !$this->compareAddress($routeItem['via'], $via)) {
                continue;
            }

            if ($dev !== null && $routeItem['dev'] !== $dev) {
                continue;
            }

            $routes[] = $routeItem;
        }

        return $routes;
    }

    /**
     * @param string $address
     * @return string
     */
    public function normalizeAddress($address) {
        $normalized = strtolower($address);

        $normalizedParts = explode('/', $normalized);

        if (count($normalizedParts) == 1) {
            return $normalized;
        }

        if (strpos($normalizedParts[0], ':') === false) {
            if ($normalizedParts[1] == '128') {
                return $normalizedParts[0];
            }
        } else {
            if ($normalizedParts[1] == '32') {
                return $normalizedParts[0];
            }
        }

        return $normalized;
    }

    /**
     * @param string $a
     * @param string $b
     * @return boolean
     */
    public function compareAddress($a, $b) {
        if ($a === $b) {
            return true;
        }

        return $this->normalizeAddress($a) === $this->normalizeAddress($b);
    }

    /**
     * @param string $routeLine
     * @return array
     */
    public function parseRouteLine($routeLine) {
        $parts = explode(" ", $routeLine);

        $route = [
            'via' => null,
            'dev' => null,
            'address' => array_shift($parts)
        ];


        if (count($parts) === 0) {
            return false;
        }

        while (in_array($parts[0], ['via', 'dev'])) {
            $item = array_shift($parts);
            $route[$item] = array_shift($parts);
        }

        return $route;
    }

    /**
     * @param string $address
     * @param string $dev
     * @throws \Exception
     */
    public function addAddress($address, $dev) {
        $this->execute('ip addr add ' . escapeshellarg($address) . ' dev ' . escapeshellarg($dev));
    }

    /**
     * @param string $address
     * @param string $dev
     * @throws \Exception
     */
    public function removeAddress($address, $dev) {
        $this->execute('ip addr del ' . escapeshellarg($address) . ' dev ' . escapeshellarg($dev));
    }

    /**
     * @param string $address
     * @param string $via
     * @param string $dev
     * @throws \Exception
     */
    public function addRoute($address, $via, $dev) {
        $command = '';

        if ($via !== null) {
            $command .= 'via ' . escapeshellarg($via) . ' ';
        }

        if ($dev !== null) {
            $command .= 'dev ' . escapeshellarg($dev);
        }

        $this->execute('ip route add ' . $address . ' ' . $command);
    }

    /**
     * @param string $address
     * @param string $via
     * @param string $dev
     * @throws \Exception
     */
    public function removeRoute($address, $via, $dev) {
        $command = '';

        if ($via !== null) {
            $command .= 'via ' . escapeshellarg($via) . ' ';
        }

        if ($dev !== null) {
            $command .= 'dev ' . escapeshellarg($dev);
        }

        $this->execute('ip route del ' . $address . ' ' . $command);
    }

    /**
     * @param string $command
     * @return bool
     */
    private function checkExecute($command) {
        exec($command, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * @param string $command
     * @return array
     * @throws \Exception
     */
    private function execute($command) {
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->log->addCritical("Command '{$command}' failed with exit code {$exitCode} and output:" . implode("\n", $output));
            throw new \Exception('Command failed');
        }

        return $output;
    }
}