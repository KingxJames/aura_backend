<?php

namespace Tests\Unit;

use App\Services\KnowledgeTracingService;
use Tests\TestCase;

class KnowledgeTracingServiceTest extends TestCase
{
    private KnowledgeTracingService $kt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kt = new KnowledgeTracingService();
    }

    public function test_correct_answer_increases_mastery(): void
    {
        $this->assertGreaterThan(0.30, $this->kt->bayesianUpdate(0.30, true));
    }

    public function test_incorrect_answer_decreases_mastery(): void
    {
        $this->assertLessThan(0.80, $this->kt->bayesianUpdate(0.80, false));
    }

    public function test_repeated_correct_answers_converge_toward_full_mastery(): void
    {
        $mastery = 0.30;
        foreach (range(1, 10) as $_) {
            $mastery = $this->kt->bayesianUpdate($mastery, true);
        }

        $this->assertGreaterThan(0.95, $mastery);
    }

    public function test_mastery_stays_within_the_zero_to_one_probability_bounds(): void
    {
        $mastery = 0.999999;
        foreach (range(1, 5) as $_) {
            $mastery = $this->kt->bayesianUpdate($mastery, false);
        }

        $this->assertGreaterThanOrEqual(0.0, $mastery);
        $this->assertLessThanOrEqual(1.0, $mastery);
    }
}
