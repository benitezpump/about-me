<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_responde_200_si_la_base_contesta(): void
    {
        $this->getJson('/healthz')
            ->assertOk()
            ->assertExactJson(['status' => 'ok'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_responde_503_si_la_base_no_contesta(): void
    {
        config(['database.connections.pgsql.url' => 'postgres://x:y@127.0.0.1:1/nada']);
        DB::purge('pgsql');

        $this->getJson('/healthz')->assertStatus(503)->assertExactJson(['status' => 'error']);
    }
}
