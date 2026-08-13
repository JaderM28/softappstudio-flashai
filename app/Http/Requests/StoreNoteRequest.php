<?php

namespace App\Http\Requests;

use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNoteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'deck_id' => [
                'nullable',
                // Scoped to the user, so a guessed id cannot file a sentence
                // into somebody else's deck.
                Rule::exists(Deck::class, 'id')->where('user_id', $this->user()->id),
            ],
            'sentence' => ['required', 'string', 'max:500'],
            'target' => ['required', 'string', 'max:120'],
            'target_lemma' => ['nullable', 'string', 'max:120'],
            'meaning' => ['nullable', 'string', 'max:500'],
            'translation' => ['nullable', 'string', 'max:500'],
            'pronunciation' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', 'string', 'max:120'],
            // Carried through from generation so the picture search runs on the
            // scene the model described rather than on the target word.
            'image_query' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target.required' => 'Mark the word or phrase you are learning.',
            'sentence.required' => 'Write the sentence you want to study.',
        ];
    }
}
