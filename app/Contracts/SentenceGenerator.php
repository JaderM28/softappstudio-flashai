<?php

namespace App\Contracts;

use App\Exceptions\GenerationFailed;
use App\Support\GeneratedNote;
use App\Support\GenerationRequest;

interface SentenceGenerator
{
    /**
     * Turn a word or a pasted sentence into a study-ready note.
     *
     * @throws GenerationFailed when the service is unreachable or answers with
     *                          something unusable
     */
    public function generate(GenerationRequest $request): GeneratedNote;
}
