<?php

namespace Tests\Unit;

use App\Services\AdaptiveEloService;
use Tests\TestCase;

class AdaptiveEloServiceTest extends TestCase
{
    private AdaptiveEloService $elo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->elo = new AdaptiveEloService();
    }

    public function test_expected_score_is_one_half_for_equal_ratings(): void
    {
        $this->assertEqualsWithDelta(0.5, $this->elo->expectedScore(1000, 1000), 0.0001);
    }

    public function test_expected_score_favors_the_higher_rated_side(): void
    {
        $this->assertGreaterThan(0.5, $this->elo->expectedScore(1200, 1000));
        $this->assertLessThan(0.5, $this->elo->expectedScore(1000, 1200));
    }

    public function test_correct_answer_raises_student_rating_and_lowers_question_rating(): void
    {
        [$student, $question] = $this->elo->updateRatings(1000, 1000, true);

        $this->assertGreaterThan(1000, $student);
        $this->assertLessThan(1000, $question);
    }

    public function test_incorrect_answer_lowers_student_rating_and_raises_question_rating(): void
    {
        [$student, $question] = $this->elo->updateRatings(1000, 1000, false);

        $this->assertLessThan(1000, $student);
        $this->assertGreaterThan(1000, $question);
    }

    public function test_beating_a_harder_question_gives_a_bigger_rating_jump_than_an_easier_one(): void
    {
        [$studentVsHard] = $this->elo->updateRatings(1000, 1400, true);
        [$studentVsEasy] = $this->elo->updateRatings(1000, 600, true);

        $this->assertGreaterThan($studentVsEasy - 1000, $studentVsHard - 1000);
    }
}
