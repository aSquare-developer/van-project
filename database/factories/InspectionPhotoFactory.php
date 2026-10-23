<?php

namespace Database\Factories;

use App\Models\InspectionPhoto;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<InspectionPhoto> */
class InspectionPhotoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uploaded_by' => User::factory(), 'purpose' => 'attempt',
            'path' => 'images/'.Str::uuid().'.png', 'mime' => 'image/png',
            'width' => 64, 'height' => 64, 'bytes' => 100, 'sha256' => str_repeat('a', 64),
        ];
    }
}
