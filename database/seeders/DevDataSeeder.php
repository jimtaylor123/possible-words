<?php

namespace Database\Seeders;

use App\Models\Definition;
use App\Models\User;
use App\Models\Vote;
use App\Models\Word;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DevDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating demo users...');
        $users = $this->createUsers();

        $this->command->info('Loading 1000 words from fixture...');
        $words = $this->createWords();

        $this->command->info('Creating definitions...');
        $this->createDefinitions($words, $users);

        $this->command->info('Creating votes and favourites...');
        $this->createVotesAndFavourites($words, $users);

        $this->command->info('Dev data seeded successfully!');
    }

    private function createUsers(): array
    {
        $demo = User::factory()->create([
            'name' => 'Demo User',
            'email' => 'demo@example.com',
            'avatar' => 'https://i.pravatar.cc/150?u=demo@example.com',
        ]);

        $users = [$demo];

        foreach (User::factory(4)->create()->all() as $user) {
            $user->update([
                'avatar' => 'https://i.pravatar.cc/150?u='.$user->email,
            ]);
            $users[] = $user;
        }

        return $users;
    }

    private function createWords(): array
    {
        $this->call(WordFixtureSeeder::class);

        return Word::all()->all();
    }

    private function createDefinitions(array $words, array $users): void
    {
        foreach ($words as $word) {
            if (mt_rand(1, 100) <= 10) {
                continue;
            }

            $count = mt_rand(3, 10);
            $definitions = [];

            for ($i = 0; $i < $count; $i++) {
                $user = $users[array_rand($users)];
                $definitions[] = [
                    'word_id' => $word->id,
                    'user_id' => $user->id,
                    'text' => fake()->realText(mt_rand(40, 120)),
                    'votes_count' => 0,
                    'created_at' => now()->subDays(mt_rand(0, 30)),
                    'updated_at' => now(),
                ];
            }

            Definition::insert($definitions);
        }
    }

    private function createVotesAndFavourites(array $words, array $users): void
    {
        $definitions = Definition::all();
        $votes = [];
        $favourites = [];

        foreach ($definitions as $definition) {
            $voterCount = mt_rand(0, count($users));
            $voterIds = [];

            for ($i = 0; $i < $voterCount; $i++) {
                $voter = $users[array_rand($users)];
                if (in_array($voter->id, $voterIds)) {
                    continue;
                }
                $voterIds[] = $voter->id;

                $votes[] = [
                    'definition_id' => $definition->id,
                    'user_id' => $voter->id,
                    'created_at' => now()->subDays(mt_rand(0, 30)),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($votes, 500) as $chunk) {
            Vote::insert($chunk);
        }

        $this->updateVoteCounts($definitions);

        foreach ($words as $word) {
            if (mt_rand(1, 100) <= 20) {
                continue;
            }
            $user = $users[array_rand($users)];
            $favourites[] = [
                'user_id' => $user->id,
                'word_id' => $word->id,
                'created_at' => now()->subDays(mt_rand(0, 30)),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($favourites, 500) as $chunk) {
            DB::table('favourites')->insert($chunk);
        }
    }

    private function updateVoteCounts($definitions): void
    {
        $counts = Vote::selectRaw('definition_id, COUNT(*) as count')
            ->groupBy('definition_id')
            ->pluck('count', 'definition_id');

        foreach ($definitions as $definition) {
            $count = $counts[$definition->id] ?? 0;
            Definition::withoutTimestamps(function () use ($definition, $count) {
                $definition->update(['votes_count' => $count]);
            });
        }
    }
}
