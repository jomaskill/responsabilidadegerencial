<?php

namespace App\Support\Profile;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class ProfileValidationRules
{
    /** @return array<string, array<int, ValidationRule|array<mixed>|string>> */
    public function rules(?int $userId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                $userId === null
                    ? Rule::unique(User::class)
                    : Rule::unique(User::class)->ignore($userId),
            ],
        ];
    }
}
