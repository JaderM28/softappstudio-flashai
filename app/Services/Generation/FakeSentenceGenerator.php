<?php

namespace App\Services\Generation;

use App\Contracts\SentenceGenerator;
use App\Exceptions\GenerationFailed;
use App\Support\GeneratedNote;
use App\Support\GenerationRequest;
use Illuminate\Support\Str;

/**
 * A stand-in for tests and for local work without an API key. Deterministic,
 * so a test can assert on exactly what came back.
 */
class FakeSentenceGenerator implements SentenceGenerator
{
    /** @var array<int, GenerationRequest> */
    public array $requests = [];

    private ?GeneratedNote $answer = null;

    private ?GenerationFailed $failure = null;

    public function willReturn(GeneratedNote $note): self
    {
        $this->answer = $note;

        return $this;
    }

    public function willFail(?GenerationFailed $failure = null): self
    {
        $this->failure = $failure ?? GenerationFailed::unreachable('Gemini');

        return $this;
    }

    public function generate(GenerationRequest $request): GeneratedNote
    {
        $this->requests[] = $request;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->answer ?? $this->plausibleAnswerFor($request);
    }

    public function called(): bool
    {
        return $this->requests !== [];
    }

    public function callCount(): int
    {
        return count($this->requests);
    }

    /**
     * Shaped like a real answer: the target appears verbatim in the sentence,
     * which is the property everything downstream depends on.
     */
    private function plausibleAnswerFor(GenerationRequest $request): GeneratedNote
    {
        if ($request->looksLikeSentence()) {
            $target = Str::of($request->input)->trim()->explode(' ')[1] ?? $request->input;

            return new GeneratedNote(
                sentence: $request->input,
                target: $target,
                targetLemma: Str::lower($target),
                meaning: "what \"{$target}\" means",
                translation: "traducción de: {$request->input}",
                pronunciation: null,
                imageQuery: 'street scene daylight',
            );
        }

        $word = Str::of($request->input)->trim()->value();

        return new GeneratedNote(
            sentence: "They talked about the {$word} all evening.",
            target: $word,
            targetLemma: Str::lower($word),
            meaning: "what \"{$word}\" means",
            translation: "Hablaron del {$word} toda la tarde.",
            pronunciation: null,
            imageQuery: 'people talking evening',
        );
    }
}
