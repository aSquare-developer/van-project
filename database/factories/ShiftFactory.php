<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Models\InspectionTemplate;
use App\Models\Shift;
use App\Models\User;
use App\Models\Van;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Shift> */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'Friday shift',
            'shift_date' => today(),
            'worker_id' => User::factory(),
            'assigned_by' => User::factory()->state(['role' => Role::Admin]),
            'van_id' => Van::factory(),
            'inspection_template_id' => InspectionTemplate::factory(),
            'template_name' => 'End of shift inspection',
            'van_name' => 'Assigned van',
            'van_registration' => null,
            'status' => ShiftStatus::Assigned,
        ];
    }
}
