<?php

namespace Tests\Unit;

use App\Support\DemoAccounts;
use PHPUnit\Framework\TestCase;

class DemoAccountsTest extends TestCase
{
    public function test_development_always_gets_demo_accounts(): void
    {
        $this->assertTrue(DemoAccounts::shouldSeed('local', 'pgsql'));
        $this->assertTrue(DemoAccounts::shouldSeed('testing', 'sqlite'));
    }

    public function test_ephemeral_production_gets_demo_accounts(): void
    {
        // SQLite in production means the host keeps no data between restarts.
        $this->assertTrue(DemoAccounts::shouldSeed('production', 'sqlite'));
        $this->assertTrue(DemoAccounts::shouldSeed('production', null));
    }

    public function test_real_production_database_never_gets_demo_accounts(): void
    {
        $this->assertFalse(DemoAccounts::shouldSeed('production', 'pgsql'));
        $this->assertFalse(DemoAccounts::shouldSeed('production', 'mysql'));
    }

    public function test_operator_flag_overrides_everything(): void
    {
        $this->assertTrue(DemoAccounts::shouldSeed('production', 'pgsql', true));
        $this->assertFalse(DemoAccounts::shouldSeed('local', 'sqlite', false));
    }
}
