<?php

namespace App\Services\Infrastructure;

class Catalog
{
    public const VERSION = 'NUERS-INFRA-1';

    public const SERVICES = [
        'load_balancer' => ['haproxy', 'keepalived'],
        'web' => ['nginx', 'php-fpm', 'nuers-api', 'queue-worker', 'scheduler', 'log-rotation'],
        'database' => ['mysql', 'backup-agent'],
        'storage' => ['ceph-mon', 'ceph-mgr', 'ceph-osd', 'backup-agent'],
    ];

    public static function inventory(): array
    {
        return [
            ['LB-01', 'load_balancer', 'Load Balancer (Primary)', 'LB-HA', '4 cores · 16 GB ECC · 2 × 240 GB SSD mirror'],
            ['LB-02', 'load_balancer', 'Load Balancer (Secondary)', 'LB-HA', '4 cores · 16 GB ECC · 2 × 240 GB SSD mirror'],
            ['WEB-01', 'web', 'Web Server (Node 1)', 'WEB-HA', '16 cores · 64 GB ECC · 2 × 960 GB SSD mirror'],
            ['WEB-02', 'web', 'Web Server (Node 2)', 'WEB-HA', '16 cores · 64 GB ECC · 2 × 960 GB SSD mirror'],
            ['DB-01', 'database', 'Database (Planned primary)', 'DB-HA', '24 cores · 128 GB ECC · 4 × 1.92 TB NVMe RAID 10'],
            ['DB-02', 'database', 'Database (Planned replica)', 'DB-HA', '24 cores · 128 GB ECC · 4 × 1.92 TB NVMe RAID 10'],
            ['ST-01', 'storage', 'Storage (Node 1)', 'ST-CLUSTER', '8 cores · 64 GB ECC · 4 × 3.84 TB SSD'],
            ['ST-02', 'storage', 'Storage (Node 2)', 'ST-CLUSTER', '8 cores · 64 GB ECC · 4 × 3.84 TB SSD'],
            ['ST-03', 'storage', 'Storage (Node 3)', 'ST-CLUSTER', '8 cores · 64 GB ECC · 4 × 3.84 TB SSD'],
        ];
    }

    public static function actions(): array
    {
        $all = array_keys(self::SERVICES);

        return [
            'service.restart' => ['label' => 'Restart service', 'risk' => 'medium', 'tiers' => $all],
            'service.reload' => ['label' => 'Reload service', 'risk' => 'medium', 'tiers' => ['web', 'load_balancer']],
            'node.reboot' => ['label' => 'Reboot server', 'risk' => 'high', 'tiers' => $all],
            'node.shutdown' => ['label' => 'Shut down server', 'risk' => 'high', 'tiers' => $all],
            'web.drain' => ['label' => 'Drain web traffic', 'risk' => 'high', 'tiers' => ['web']],
            'web.rejoin' => ['label' => 'Rejoin web pool', 'risk' => 'high', 'tiers' => ['web']],
            'web.rolling_restart' => ['label' => 'Rolling web restart', 'risk' => 'high', 'tiers' => ['web']],
            'lb.failover' => ['label' => 'Load balancer failover', 'risk' => 'high', 'tiers' => ['load_balancer']],
            'database.promote' => ['label' => 'Promote database replica', 'risk' => 'critical', 'tiers' => ['database']],
            'storage.maintenance' => ['label' => 'Ceph maintenance', 'risk' => 'critical', 'tiers' => ['storage']],
            'backup.create' => ['label' => 'Create backup', 'risk' => 'medium', 'tiers' => $all],
            'restore.test' => ['label' => 'Isolated restore test', 'risk' => 'high', 'tiers' => $all],
            'restore.production' => ['label' => 'Production restore', 'risk' => 'critical', 'tiers' => $all],
            'configuration.deploy' => ['label' => 'Deploy reviewed configuration', 'risk' => 'critical', 'tiers' => $all],
            'network.change' => ['label' => 'Apply reviewed network change', 'risk' => 'critical', 'tiers' => $all],
            'console.open' => ['label' => 'Request recorded console', 'risk' => 'critical', 'tiers' => $all],
        ];
    }

    public static function risk(object $node, string $action): string
    {
        return in_array($node->tier, ['database', 'storage']) && $action !== 'backup.create' ? 'critical' : self::actions()[$action]['risk'];
    }
}
