<?php

namespace Tests\Unit;

use App\Services\RuntimeDatabasePrivileges;
use PHPUnit\Framework\TestCase;

class RuntimeDatabasePrivilegesTest extends TestCase
{
    private function grants(): array
    {
        return array_map(fn ($p) => ['scope' => 'schema', 'schema' => 'NUERS', 'privilege' => $p, 'grantable' => false], ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
    }

    public function test_only_local_schema_dml_passes(): void
    {
        $s = new RuntimeDatabasePrivileges();
        $this->assertTrue($s->assess('nuers_runtime@localhost', 'NUERS', $this->grants())['least_privilege_passed']);
        $this->assertFalse($s->assess('nuers_runtime@%', 'NUERS', $this->grants())['least_privilege_passed']);
        $this->assertFalse($s->assess('nuers_runtime@localhost', 'NUERS', [], 'NONE')['least_privilege_passed']);
        $this->assertFalse($s->assess('nuers_runtime@localhost', 'NUERS', $this->grants(), 'operator')['least_privilege_passed']);
    }

    public function test_schema_ddl_global_cross_schema_and_grant_delegation_fail(): void
    {
        foreach ([['schema', 'NUERS', 'DROP', false], ['global', null, 'SELECT', false], ['schema', 'another', 'SELECT', false], ['schema', 'NUERS', 'SELECT', true]] as [$scope, $schema, $privilege, $grantable]) {
            $grants = [...$this->grants(), compact('scope', 'schema', 'privilege', 'grantable')];
            $this->assertFalse((new RuntimeDatabasePrivileges())->assess('nuers_runtime@localhost', 'NUERS', $grants)['least_privilege_passed']);
        }
    }
}
