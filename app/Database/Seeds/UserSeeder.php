<?php

namespace App\Database\Seeds;

use App\Models\UserModel;
use CodeIgniter\Database\Seeder;
use Faker\Factory;
use RuntimeException;

/**
 * Seeds 20 reproducible users (fixed Faker seed). Safe to run more than once.
 */
class UserSeeder extends Seeder
{
    private const TOTAL      = 20;
    private const FAKER_SEED = 20261115;

    public function run()
    {
        $faker = Factory::create('es_ES');
        $faker->seed(self::FAKER_SEED);

        $model = new UserModel();

        for ($i = 0; $i < self::TOTAL; $i++) {
            $row = [
                'first_name' => $faker->firstName(),
                'last_name'  => $faker->lastName(),
                'email'      => $faker->unique()->safeEmail(),
                'gender'     => $faker->randomElement(['male', 'female', 'other']),
                'age'        => $faker->numberBetween(18, 80),
                'telephone'  => $faker->boolean(30) ? null : $faker->numerify('+34 6## ### ###'),
            ];

            // Idempotent: the fixed seed yields the same emails on every run,
            // so a re-run skips users that already exist instead of failing.
            if ($model->where('email', $row['email'])->countAllResults() > 0) {
                continue;
            }

            // insert() normalizes email, validates and sets timestamps.
            if ($model->insert($row) === false) {
                throw new RuntimeException('UserSeeder: invalid row: ' . json_encode($model->errors()));
            }
        }
    }
}
