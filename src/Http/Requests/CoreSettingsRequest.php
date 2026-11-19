<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Craft's generic plugin-settings save (`POST settings/plugins/chisel`) is refused:
 * Chisel's settings — the allowlist above all — are saved only from Chisel → Settings,
 * which also requires an elevated session.
 */
class CoreSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
