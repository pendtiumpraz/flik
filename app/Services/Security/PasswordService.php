<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;
use App\Rules\NotBreached;
use App\Rules\StrongPassword;
use Illuminate\Support\Facades\Validator;

/**
 * Façade in front of {@see StrongPassword} + {@see NotBreached}.
 *
 * The Laravel form-request flow uses the rules directly (cleanest UX —
 * messages bubble up next to the field). This service exists for the
 * "I just have a password string and want a yes/no" callers:
 *
 *   - Console commands seeding test users.
 *   - The eventual API password-change endpoint.
 *   - Future password-reset flow.
 *   - Tests that want to assert the policy without spinning a request.
 *
 * Returns a structured result rather than throwing, because most callers
 * want to render the failures back to the UI rather than rescue an
 * exception.
 */
final class PasswordService
{
    /**
     * Validate a candidate password against the full FLiK policy.
     *
     * @param  string     $password The candidate password (plaintext).
     * @param  User|null  $context  Owning user, for identity-derived
     *                              dictionary checks (name/email/username).
     * @return array{valid: bool, errors: list<string>}
     */
    public function validate(string $password, ?User $context = null): array
    {
        $validator = Validator::make(
            ['password' => $password],
            [
                'password' => [
                    'required',
                    'string',
                    new StrongPassword($context),
                    new NotBreached(),
                ],
            ],
        );

        if ($validator->passes()) {
            return ['valid' => true, 'errors' => []];
        }

        /** @var list<string> $errors */
        $errors = array_values($validator->errors()->get('password'));

        return ['valid' => false, 'errors' => $errors];
    }
}
