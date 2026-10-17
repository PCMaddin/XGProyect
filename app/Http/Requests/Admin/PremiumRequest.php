<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class PremiumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'premium_url' => ['nullable', 'url', 'max:254'],
            'premium_mode' => ['nullable', 'in:pay2win,play2win'],
            'payment_methods' => ['nullable', 'array'],
            'payment_methods.*' => ['string', 'in:stripe,wax'],
            'payment_methods_form' => ['nullable'],
            'registration_dark_matter' => ['nullable', 'integer', 'min:0'],
            'merchant_price' => ['nullable', 'numeric', 'min:0'],
            'merchant_base_min_exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'merchant_base_max_exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'merchant_metal_multiplier' => ['nullable', 'numeric', 'min:0'],
            'merchant_crystal_multiplier' => ['nullable', 'numeric', 'min:0'],
            'merchant_deuterium_multiplier' => ['nullable', 'numeric', 'min:0'],
            'merchant_scrap_base_rate' => ['nullable', 'integer', 'min:0', 'max:100'],
            'merchant_scrap_max_rate' => ['nullable', 'integer', 'min:0', 'max:100'],
            'merchant_scrap_bargain_price' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw ValidationException::withMessages($validator->errors()->toArray())
            ->redirectTo(route('admin.premium'));
    }
}
