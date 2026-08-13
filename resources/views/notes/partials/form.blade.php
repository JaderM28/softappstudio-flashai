@props(['note' => null, 'decks', 'selectedDeckId'])

<div class="space-y-5">

    <div>
        <x-input-label for="sentence" :value="__('Sentence')" />
        <textarea id="sentence" name="sentence" rows="2" required autofocus
                  placeholder="She borrowed my umbrella yesterday."
                  class="mt-1 block w-full text-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-xs">{{ old('sentence', $note?->sentence) }}</textarea>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {{ __('One sentence, with exactly one thing in it you do not already know.') }}
        </p>
        <x-input-error :messages="$errors->get('sentence')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="target" :value="__('Word or phrase you are learning')" />
        <x-text-input id="target" name="target" type="text" required
                      placeholder="borrowed"
                      class="mt-1 block w-full"
                      :value="old('target', $note?->target)" />
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {{ __('Copy it exactly as it appears above — this is what gets blanked out.') }}
        </p>
        <x-input-error :messages="$errors->get('target')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="meaning" :value="__('Meaning')" />
        <x-text-input id="meaning" name="meaning" type="text"
                      placeholder="took something to use and give back later"
                      class="mt-1 block w-full"
                      :value="old('meaning', $note?->meaning)" />
        <x-input-error :messages="$errors->get('meaning')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="translation" :value="__('Translation')" />
        <x-text-input id="translation" name="translation" type="text"
                      placeholder="Ayer me pidió prestado el paraguas."
                      class="mt-1 block w-full"
                      :value="old('translation', $note?->translation)" />
        <x-input-error :messages="$errors->get('translation')" class="mt-2" />
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <div>
            <x-input-label for="deck_id" :value="__('Deck')" />
            <select id="deck_id" name="deck_id"
                    class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-xs">
                @foreach ($decks as $deck)
                    <option value="{{ $deck->id }}" @selected(old('deck_id', $selectedDeckId) == $deck->id)>
                        {{ $deck->name }}
                    </option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('deck_id')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="source" :value="__('Where you found it')" />
            <x-text-input id="source" name="source" type="text"
                          placeholder="The Wire, s02e04"
                          class="mt-1 block w-full"
                          :value="old('source', $note?->source)" />
            <x-input-error :messages="$errors->get('source')" class="mt-2" />
        </div>
    </div>

</div>
