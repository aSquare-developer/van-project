<?php

namespace Database\Factories;

use App\Enums\ShiftDecision;
use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\ShiftTransition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ShiftTransition> */
class ShiftTransitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(), 'actor_id' => User::factory(),
            'request_key' => Str::uuid(), 'decision' => ShiftDecision::RequestReview,
            'from_status' => ShiftStatus::InProgress, 'to_status' => ShiftStatus::AwaitingReview,
            'revision_before' => 0, 'revision_after' => 1,
            'comment' => 'Please review the inspection photos.', 'snapshot' => [],
        ];
    }
}
