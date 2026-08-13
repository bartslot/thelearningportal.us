<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A teacher reporting that a territory is wrong.
 *
 * The year bounds match the Time-Map's own slider: it runs from deep prehistory to the present, and
 * anything outside that could not have been on screen when the report was written.
 *
 * Note what is NOT accepted here: `confidence` and `chosen_source`. Those are what WE claim about
 * the shape, and the server stamps them from database/data/hand-territories.json. Taking them from
 * the request would let a report assert its own context, which destroys the only thing a report is
 * for. See TerritoryReportController.
 */
class StoreTerritoryReportRequest extends FormRequest
{
    /** The Time-Map slider's own range. */
    private const EARLIEST_YEAR = -12000;

    private const LATEST_YEAR = 2100;

    public function authorize(): bool
    {
        // The route is behind the teacher auth middleware; any signed-in teacher may report.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // One of these two must identify the territory; see withValidator.
            'territory_id' => ['nullable', 'string', 'max:64'],
            'wikidata_qid' => ['nullable', 'string', 'regex:/^Q\d+$/'],

            'label' => ['required', 'string', 'max:255'],
            'source' => ['required', 'string', 'in:hand,cliopatria'],
            'year' => ['required', 'integer', 'between:'.self::EARLIEST_YEAR.','.self::LATEST_YEAR],
            'comment' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    protected function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            // A report that names no territory cannot be acted on, and the two id columns are
            // individually optional — so the rule lives here rather than in `required_without`,
            // which would report the failure against whichever field happened to be listed first.
            if (blank($this->input('territory_id')) && blank($this->input('wikidata_qid'))) {
                $validator->errors()->add('territory_id', __('A report must say which territory it is about.'));
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'comment.required' => __('Please describe what looks wrong.'),
            'comment.min' => __('Please describe what looks wrong.'),
        ];
    }
}
