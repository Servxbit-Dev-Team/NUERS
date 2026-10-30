<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Read-only inspection. Never emit SHOW GRANTS authentication hashes or change grants. */
class RuntimeDatabasePrivileges
{
    public function inspect(): array
    {
        $db = DB::connection();
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Privilege inspection requires MySQL or MariaDB. SQLite is not equivalent.');
        }
        $identity = (string) $db->selectOne('SELECT CURRENT_USER() AS identity')->identity;
        $at = strrpos($identity, '@');
        if ($at === false) {
            throw new RuntimeException('Cannot identify the effective database principal.');
        }
        $grantee = "'".str_replace("'", "''", substr($identity, 0, $at))."'@'".str_replace("'", "''", substr($identity, $at + 1))."'";
        $grants = [];
        foreach (['USER_PRIVILEGES' => 'global', 'SCHEMA_PRIVILEGES' => 'schema', 'TABLE_PRIVILEGES' => 'table', 'COLUMN_PRIVILEGES' => 'column'] as $table => $scope) {
            $columns = $scope === 'global' ? 'PRIVILEGE_TYPE, IS_GRANTABLE' : 'TABLE_SCHEMA, PRIVILEGE_TYPE, IS_GRANTABLE';
            foreach ($db->select("SELECT $columns FROM information_schema.$table WHERE GRANTEE = ?", [$grantee]) as $row) {
                $grants[] = ['scope' => $scope, 'schema' => $row->TABLE_SCHEMA ?? null, 'privilege' => $row->PRIVILEGE_TYPE, 'grantable' => $row->IS_GRANTABLE === 'YES'];
            }
        }
        // Active role grants require their own review; do not silently certify inherited access.
        try {
            $role = (string) ($db->selectOne('SELECT CURRENT_ROLE() AS active_role')->active_role ?? 'NONE');
        } catch (\Throwable) {
            $role = 'UNVERIFIED';
        }

        return $this->assess($identity, $db->getDatabaseName(), $grants, $role);
    }

    public function assess(string $identity, string $schema, array $grants, string $activeRole = 'NONE'): array
    {
        $allowed = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];
        $host = substr($identity, (strrpos($identity, '@') ?: 0) + 1);
        $issues = [];
        if ($host !== 'localhost') {
            $issues[] = 'Runtime principal must be restricted to localhost for this socket-based deployment.';
        }
        if (! in_array(strtoupper($activeRole), ['', 'NONE', 'NULL'], true)) {
            $issues[] = 'Active or unverified role grants require a separate review.';
        }
        $present = [];
        foreach ($grants as $grant) {
            $p = strtoupper($grant['privilege']);
            if ($grant['grantable']) {
                $issues[] = 'Runtime principal has grant delegation.';
            }
            if ($p === 'USAGE' && $grant['scope'] === 'global') {
                continue;
            }
            if ($grant['scope'] === 'global' || $grant['schema'] !== $schema || ! in_array($p, $allowed, true)) {
                $issues[] = 'Excess privilege: '.$grant['scope'].' '.$p.' on '.($grant['schema'] ?? '*').'.';
            } elseif ($grant['scope'] === 'schema') {
                $present[] = $p;
            }
        }
        if (array_diff($allowed, $present)) {
            $issues[] = 'Required schema-level runtime DML grants are missing.';
        }

        return ['observed_at' => gmdate('c'), 'principal' => $identity, 'database' => $schema,
            'least_privilege_passed' => $issues === [], 'issues' => array_values(array_unique($issues)),
            'scope' => 'Runtime database account only; application role permissions, operator credentials and trigger definers require separate evidence.'];
    }
}
