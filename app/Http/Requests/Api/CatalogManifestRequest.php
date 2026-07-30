<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;

/**
 * Validates the catalogue manifest's one input.
 *
 * `since` is whatever `generated_at` the client kept from its last sync, so it is always an
 * ISO 8601 timestamp. Rejecting a malformed one here (rather than letting Carbon throw deep in a
 * query) is the difference between a 422 that says which field is wrong and a 500.
 */
class CatalogManifestRequest extends FormRequest
{
    /** Public catalogue — the lessons it lists are playable without an account. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'since' => ['sometimes', 'nullable', 'string', 'date'],
        ];
    }

    /** The cut-off for an incremental sync, or null for a full one. */
    public function since(): ?Carbon
    {
        $since = trim((string) $this->query('since', ''));

        return $since === '' ? null : Carbon::parse($since);
    }

    /**
     * Answer with JSON whatever the client asked for.
     *
     * Laravel redirects a failed FormRequest back to the previous page unless the request says it
     * wants JSON, so a sync client that forgets the Accept header got a 302 to the landing page
     * instead of a validation error — invisible until it tries to parse the HTML.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
