<?php

namespace App\Services;

use App\Models\TopicMastery;

/**
 * Standard Bayesian Knowledge Tracing (Corbett & Anderson, 1995):
 * tracks P(mastery) per student per topic and updates it from each
 * observed answer via Bayes' rule, then applies a learning transition.
 */
class KnowledgeTracingService
{
    private const PRIOR_MASTERY = 0.30; // P(L0): assumed mastery before any evidence
    private const P_TRANSIT = 0.10;     // P(T): chance of learning the skill after an attempt
    private const P_SLIP = 0.10;        // P(S): chance of a wrong answer despite mastery
    private const P_GUESS = 0.25;       // P(G): chance of a right answer without mastery (1-in-4 options)

    private const TOPIC_PICK_EPSILON = 0.05; // keeps even mastered topics pickable occasionally

    public function getOrCreateMastery(int $userId, string $topic): TopicMastery
    {
        return TopicMastery::firstOrCreate(
            ['user_id' => $userId, 'topic' => $topic],
            ['mastery' => self::PRIOR_MASTERY]
        );
    }

    /**
     * Bayesian posterior update given one observed answer, followed by the
     * learning transition. Returns the new P(mastery).
     */
    public function bayesianUpdate(float $priorMastery, bool $isCorrect): float
    {
        if ($isCorrect) {
            $numerator = $priorMastery * (1 - self::P_SLIP);
            $denominator = $numerator + (1 - $priorMastery) * self::P_GUESS;
        } else {
            $numerator = $priorMastery * self::P_SLIP;
            $denominator = $numerator + (1 - $priorMastery) * (1 - self::P_GUESS);
        }

        $posterior = $denominator > 0 ? $numerator / $denominator : $priorMastery;
        $newMastery = $posterior + (1 - $posterior) * self::P_TRANSIT;

        return min(1.0, max(0.0, $newMastery));
    }

    public function recordAttempt(int $userId, string $topic, bool $isCorrect): TopicMastery
    {
        $record = $this->getOrCreateMastery($userId, $topic);

        $record->mastery = $this->bayesianUpdate($record->mastery, $isCorrect);
        $record->attempts += 1;
        $record->save();

        return $record;
    }

    /**
     * Current mastery for each topic, without persisting rows for topics
     * the student hasn't attempted yet (they just report the prior).
     */
    public function getMasteryMap(int $userId, array $topics): array
    {
        $existing = TopicMastery::where('user_id', $userId)
            ->whereIn('topic', $topics)
            ->pluck('mastery', 'topic');

        $map = [];
        foreach ($topics as $topic) {
            $map[$topic] = round($existing[$topic] ?? self::PRIOR_MASTERY, 4);
        }

        return $map;
    }

    /**
     * Weighted-random topic pick that favors the student's weakest topics
     * for diagnostic targeting, while still leaving room for review of
     * already-strong topics.
     */
    public function pickWeightedTopic(int $userId, array $topics): string
    {
        $masteryMap = $this->getMasteryMap($userId, $topics);

        $weights = [];
        foreach ($topics as $topic) {
            $weights[$topic] = (1 - $masteryMap[$topic]) + self::TOPIC_PICK_EPSILON;
        }

        $totalWeight = array_sum($weights);
        $roll = mt_rand() / mt_getrandmax() * $totalWeight;

        $cumulative = 0.0;
        foreach ($weights as $topic => $weight) {
            $cumulative += $weight;
            if ($roll <= $cumulative) {
                return $topic;
            }
        }

        return $topics[array_rand($topics)];
    }
}
