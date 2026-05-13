<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @psalm-suppress MissingTemplateParam
 */
class UserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = User::class;

    /**
     * Define the model's default state.
     *
     * NOTE: `email_verified_at` and `remember_token` are intentionally NOT
     * in User::$fillable (mass-assignment audit, 2026-05-13). Factories
     * persist them through the configure() afterMaking hook below so
     * tests still get verified users out of the box.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
        ];
    }

    /**
     * Stamp guarded fields (verification + remember_token) via forceFill so
     * the User::$fillable allowlist can stay tight without breaking tests.
     */
    public function configure()
    {
        return $this->afterMaking(function (User $user): void {
            if ($user->email_verified_at === null && ! array_key_exists('email_verified_at', $user->getAttributes())) {
                $user->forceFill(['email_verified_at' => now()]);
            }
            if (empty($user->remember_token)) {
                $user->forceFill(['remember_token' => Str::random(10)]);
            }
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function unverified()
    {
        return $this->afterMaking(function (User $user): void {
            $user->forceFill(['email_verified_at' => null]);
        });
    }
}
