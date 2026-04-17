<?php

namespace Tabula17\Satelles\Odf\Adiutor\Config;

use Closure;
use Psr\Log\LoggerInterface;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\ServerHealthMonitorInterface;
use Tabula17\Satelles\Utilis\Collection\ConnectionCollection;
use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class UnoserverLoadBalancerConfig extends AbstractDescriptor
{

    protected(set) ServerHealthMonitorInterface $healthMonitor;
    protected(set) ConnectionCollection $servers
        {
            set(ConnectionCollection|array $servers) {
                $this->servers = is_array($servers) ? new ConnectionCollection(...$servers) : $servers;
            }
        }
    protected(set) int $concurrency = 10;
    protected(set) int $timeout = 10;
    protected(set) ?LoggerInterface $logger = null;
    protected(set) int $maxRetries = 3;
    protected(set) ?Closure $clientFactory = null;
}