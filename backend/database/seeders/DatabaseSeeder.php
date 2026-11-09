<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CurriculumSeeder::class,
            FullCurriculumSeeder::class,
            Grade12MathSeeder::class,
            Grade12MathVisualsSeeder::class,
            AuthoredLessonsModelsSeeder::class,
            ScienceModelsSeeder::class,
            UsersAndGamesSeeder::class,
            AgentSeeder::class,
        ]);
    }
}
