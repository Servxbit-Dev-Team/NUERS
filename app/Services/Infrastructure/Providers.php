<?php

namespace App\Services\Infrastructure;

use Illuminate\Validation\Rule;

class Providers
{
    public const NAMES = ['aws' => 'Amazon Web Services', 'contabo' => 'Contabo',
        'dataplugs' => 'Dataplugs', 'other' => 'Other provider', 'unassigned' => 'Not assigned'];

    public const TYPES = [
        'virtual_machine' => ['label' => 'Virtual machine', 'providers' => ['aws', 'contabo', 'dataplugs', 'other', 'unassigned'], 'tiers' => ['load_balancer', 'web', 'database', 'storage']],
        'dedicated_server' => ['label' => 'Dedicated server', 'providers' => ['contabo', 'dataplugs', 'other'], 'tiers' => ['load_balancer', 'web', 'database', 'storage']],
        'managed_load_balancer' => ['label' => 'Managed load balancer (ALB)', 'providers' => ['aws'], 'tiers' => ['load_balancer']],
        'managed_database' => ['label' => 'Managed database (RDS)', 'providers' => ['aws'], 'tiers' => ['database']],
        'managed_filesystem' => ['label' => 'Managed shared filesystem (EFS)', 'providers' => ['aws'], 'tiers' => ['storage']],
        'object_storage' => ['label' => 'Object storage bucket', 'providers' => ['aws', 'contabo', 'other'], 'tiers' => ['storage']],
        'ceph_node' => ['label' => 'Verified Ceph storage node', 'providers' => ['aws', 'contabo', 'dataplugs', 'other'], 'tiers' => ['storage']],
    ];

    public static function rules(bool $required = false): array
    {
        return [
            'provider' => [$required ? 'required' : 'sometimes', Rule::in(array_keys(self::NAMES))],
            'resource_type' => [$required ? 'required' : 'sometimes', Rule::in(array_keys(self::TYPES))],
            'provider_reference' => ['nullable', 'string', 'max:200', 'regex:/^[a-zA-Z0-9_:.\/\-]+$/'],
            'region' => ['nullable', 'string', 'max:80', 'regex:/^[a-zA-Z0-9 ._()\-]+$/'],
            'network_zone' => ['nullable', 'string', 'max:80', 'regex:/^[a-zA-Z0-9 ._\-]+$/'],
        ];
    }

    public static function validateCombination(string $provider, string $type, string $tier): void
    {
        abort_unless(isset(self::TYPES[$type]) && in_array($provider, self::TYPES[$type]['providers'], true)
            && in_array($tier, self::TYPES[$type]['tiers'], true), 422, 'Provider, resource type and server tier do not match.');
    }

    public static function managed(object $node): bool
    {
        return in_array($node->resource_type ?? '', ['managed_load_balancer', 'managed_database', 'object_storage', 'managed_filesystem'], true);
    }

    public static function actions(object $node): array
    {
        $all = array_keys(array_filter(Catalog::actions(), fn ($a) => in_array($node->tier, $a['tiers'], true)));
        $allowed = match ($node->resource_type ?? 'virtual_machine') {
            'managed_load_balancer' => ['configuration.deploy'],
            'managed_database' => ['backup.create', 'restore.test', 'configuration.deploy'],
            'managed_filesystem' => ['backup.create', 'restore.test'],
            'object_storage' => ['restore.test', 'configuration.deploy'],
            default => $all,
        };
        if (($node->provider ?? 'unassigned') !== 'unassigned' && ($node->resource_type ?? '') !== 'ceph_node') {
            $allowed = array_diff($allowed, ['storage.maintenance']);
        }

        return array_values(array_intersect($all, $allowed));
    }

    public static function services(object $node): array
    {
        return self::managed($node) ? [] : Catalog::SERVICES[$node->tier];
    }

    public static function identityMatches(object $node, array $pin): bool
    {
        if (($node->provider ?? 'unassigned') === 'unassigned') {
            return true; // Existing nodes keep their original certificate-bound contract.
        }
        foreach (['provider', 'resource_type', 'provider_reference', 'region', 'network_zone'] as $key) {
            if (! is_string($node->$key ?? null) || $node->$key === '' || ($pin[$key] ?? null) !== $node->$key) {
                return false;
            }
        }

        return true;
    }

    public static function identity(object $node): array
    {
        return array_intersect_key((array) $node, array_flip(['provider', 'resource_type', 'provider_reference', 'region', 'network_zone']));
    }


    public static function consoleUrl(object $node): ?string
    {
        if (($node->provider ?? '') !== 'aws' || ! preg_match('/^ap-southeast-[12]$/', $node->region ?? '')) {
            return null;
        }
        $region = $node->region;
        $ref = rawurlencode($node->provider_reference ?? '');
        $base = "https://{$region}.console.aws.amazon.com";
        return match ($node->resource_type) {
            'virtual_machine' => "$base/ec2/home?region=$region#InstanceDetails:instanceId=$ref",
            'managed_database' => "$base/rds/home?region=$region#database:id=$ref;is-cluster=false",
            'managed_load_balancer' => "$base/ec2/home?region=$region#LoadBalancer:loadBalancerArn=$ref",
            'object_storage' => "https://s3.console.aws.amazon.com/s3/buckets/$ref?region=$region&tab=objects",
            'managed_filesystem' => "$base/efs/home?region=$region#/file-systems/$ref",
            default => null,
        };
    }

    public static function catalog(): array
    {
        return ['providers' => self::NAMES, 'resource_types' => self::TYPES,
            'budget_ceiling_php' => (int) config('infrastructure.budget_ceiling_php', 100000),
            'purchasing_enabled' => false,
            'guidance' => [
                'aws' => 'EC2, ALB, RDS and S3 have different controls. Provision through reviewed infrastructure changes.',
                'contabo' => 'VPS/VDS API support depends on the subscribed product. Storage VPS and dedicated hardware require a verified management path.',
                'dataplugs' => 'Enroll a verified host agent or private IPMI management path. API purchasing is not configured.',
                'other' => 'Use a reviewed controller adapter and verified resource identity.',
            ]];
    }
}
