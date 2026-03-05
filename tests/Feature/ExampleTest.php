<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_dashboard_page_loads(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Mission Control');
    }

    public function test_mission_api_returns_payload_shape(): void
    {
        $this->getJson('/api/mission')
            ->assertOk()
            ->assertJsonStructure([
                'statusItems',
                'columns' => ['planned', 'backlog', 'active', 'review', 'done'],
                'week',
                'weekItems',
                'nowItems',
                'waitingItems',
                'weekUnscheduled',
                'liveProcesses',
                'fetchedAt',
                'sources',
            ]);
    }
}
