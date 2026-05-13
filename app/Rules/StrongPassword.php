<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enforce FLiK's password policy.
 *
 * - Minimum length (config('security.password.min_length'), default 10).
 * - At least one upper, one lower, one digit, and (optionally) one symbol.
 * - Reject a built-in blocklist of the most-common passwords.
 * - Reject obvious dictionary words derived from the user's email/name/username
 *   (only when a $context user is supplied at construction time).
 *
 * Bilingual error messages (Indonesian + English) are returned on the same
 * line so both audiences see them — the registration form is bilingual.
 *
 * Companion rule: {@see \App\Rules\NotBreached} adds an HIBP k-anonymity
 * check. The two are intentionally separate so callers can compose them
 * independently in custom flows (e.g. a "low-friction" guest checkout).
 */
final class StrongPassword implements ValidationRule
{
    /**
     * Top common-password blocklist (truncated top-100). Matched
     * case-insensitively after stripping surrounding whitespace.
     *
     * Sources: NCSC top-100, SecLists rockyou top-100, plus a handful of
     * Indonesian-keyboard variants we see attempted on this property.
     *
     * @var list<string>
     */
    private const COMMON_PASSWORDS = [
        '123456', '123456789', 'qwerty', 'password', '12345', '12345678',
        '111111', '1234567', '123123', 'qwerty123', '1q2w3e4r', 'admin',
        'password1', 'iloveyou', '1234', 'letmein', 'monkey', '123321',
        'abc123', 'qwertyuiop', '654321', '666666', 'welcome', 'login',
        'passw0rd', 'zaq12wsx', 'p@ssw0rd', 'p@ssword', 'master', 'dragon',
        'sunshine', 'football', 'baseball', 'shadow', 'ninja', 'hello',
        'starwars', 'whatever', 'trustno1', 'jordan23', 'jennifer',
        'michael', 'mustang', 'access', 'flower', 'hottie', 'loveme',
        'zaq1zaq1', '1qaz2wsx', '!qaz2wsx', 'querty', 'azerty', 'asdfgh',
        'asdfghjkl', 'qazwsx', 'qazwsxedc', '00000000', 'aaaaaa',
        'samsung', 'iphone', 'apple', 'google', 'facebook', 'twitter',
        'admin123', 'root', 'toor', 'guest', 'test', 'test123', 'demo',
        'changeme', 'secret', 'master123', 'superman', 'batman', 'pokemon',
        'minecraft', 'fortnite', 'gabriel', 'soccer', 'killer', 'jordan',
        'andrew', 'charlie', 'daniel', 'thomas', 'george', 'matthew',
        'harley', 'ranger', 'silver', 'tigger', 'fuckyou', 'asshole',
        // Indonesian common
        'rahasia', 'sayang', 'cintaku', 'indonesia', 'jakarta', 'bismillah',
        'alhamdulillah', 'kucing', 'anjing', 'sayangku',
    ];

    /**
     * Symbol set treated as "non-alnum" for the require_symbol rule.
     * Defined explicitly (rather than \W) to exclude unicode letters that
     * regex \W would otherwise count as symbols.
     */
    private const SYMBOL_REGEX = '/[^A-Za-z0-9]/';

    /**
     * @param User|null $context  Optional account for dictionary checks.
     *                            Pass the user being created/updated so we
     *                            can reject "dobeon123" for dobeon@gmail.com.
     */
    public function __construct(
        private readonly ?User $context = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string. / :attribute harus berupa teks.');

            return;
        }

        $minLength = (int) config('security.password.min_length', 10);
        $requireSymbol = (bool) config('security.password.require_symbol', true);

        if (mb_strlen($value) < $minLength) {
            $fail("Password minimal {$minLength} karakter. / Password must be at least {$minLength} characters.");

            return;
        }

        if (! preg_match('/[A-Z]/', $value)) {
            $fail('Password harus mengandung minimal 1 huruf besar. / Password must contain at least 1 uppercase letter.');

            return;
        }

        if (! preg_match('/[a-z]/', $value)) {
            $fail('Password harus mengandung minimal 1 huruf kecil. / Password must contain at least 1 lowercase letter.');

            return;
        }

        if (! preg_match('/[0-9]/', $value)) {
            $fail('Password harus mengandung minimal 1 angka. / Password must contain at least 1 digit.');

            return;
        }

        if ($requireSymbol && ! preg_match(self::SYMBOL_REGEX, $value)) {
            $fail('Password harus mengandung minimal 1 simbol. / Password must contain at least 1 symbol.');

            return;
        }

        $needle = mb_strtolower(trim($value));

        foreach (self::COMMON_PASSWORDS as $common) {
            if ($needle === $common) {
                $fail('Password ini terlalu umum. Gunakan kombinasi yang lebih unik. / This password is too common. Choose something more unique.');

                return;
            }
        }

        // Reject obvious dictionary words taken from the user's own identity.
        // Only runs when caller passed a $context user — registration form
        // builds the rule with the not-yet-saved User instance.
        if ($this->context !== null) {
            foreach ($this->dictionaryFragments() as $fragment) {
                if ($fragment !== '' && mb_strlen($fragment) >= 4 && str_contains($needle, $fragment)) {
                    $fail('Password tidak boleh mengandung nama / email Anda. / Password must not contain your name or email.');

                    return;
                }
            }
        }
    }

    /**
     * Identity-derived fragments to forbid inside the password.
     *
     * @return list<string>
     */
    private function dictionaryFragments(): array
    {
        $user = $this->context;
        if ($user === null) {
            return [];
        }

        $fragments = [];

        if (is_string($user->email) && $user->email !== '') {
            $username = mb_strtolower(strtok($user->email, '@') ?: '');
            if ($username !== '') {
                $fragments[] = $username;
            }
        }

        if (is_string($user->name ?? null) && $user->name !== '') {
            foreach (preg_split('/\s+/', mb_strtolower($user->name)) ?: [] as $piece) {
                if ($piece !== '') {
                    $fragments[] = $piece;
                }
            }
        }

        if (isset($user->username) && is_string($user->username) && $user->username !== '') {
            $fragments[] = mb_strtolower($user->username);
        }

        return array_values(array_unique($fragments));
    }
}
