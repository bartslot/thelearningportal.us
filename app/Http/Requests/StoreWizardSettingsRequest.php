<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Support\ImageStyleTemplate;
use Illuminate\Foundation\Http\FormRequest;

class StoreWizardSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'topic'            => ['required', 'string', 'min:3', 'max:100'],
            'subject'          => ['required', 'in:history,science,literature,civics'],
            'grade_level'      => ['required', 'string', 'max:50'],
            'tone'             => ['nullable', 'string', 'max:150'],
            'details'          => ['nullable', 'string', 'max:500'],
            'source_mode'      => ['required', 'in:wikipedia,upload,both'],
            'sourceUpload'     => ['nullable', 'required_if:source_mode,upload', 'file', 'mimes:pdf,docx', 'max:10240'],
            'image_style'      => ['required', 'in:' . implode(',', ImageStyleTemplate::styles())],
            'avatar_id'        => ['required', 'exists:avatars,id'],
            'strategy_game_id' => ['nullable', 'exists:strategy_games,id'],
            'team_count'       => ['nullable', 'integer', 'min:1', 'max:8'],
            'game_split_count' => ['required', 'integer', 'min:1', 'max:4'],
            'lesson_code'      => ['required', 'string', 'alpha_num', 'min:4', 'max:8'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:60'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:59'],
            'portrait'         => ['nullable', 'image', 'max:4096'],
        ];
    }
}
