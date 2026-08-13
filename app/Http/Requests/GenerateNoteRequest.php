<?php

namespace App\Http\Requests;

use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateNoteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A word, or a whole sentence pasted from wherever it was met.
            'input' => ['required', 'string', 'min:2', 'max:500'],
            'deck_id' => [
                'nullable',
                Rule::exists(Deck::class, 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'input.required' => 'Type a word, or paste a sentence you want to study.',
        ];
    }
}
