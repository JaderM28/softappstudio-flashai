# Recorded responses

These are real answers from the live services, captured on **15 August 2026**.
They are not written by hand, and that is the whole point.

## Why they exist

Every `Http::fake()` in this suite used to be an array typed out from the same
documentation the client was written from. A test built that way can only
confirm that the client agrees with its author, and the suite stayed green for
weeks while `GeminiTextToSpeech` produced no audio at all: Google's example
shows the clip at `output_audio.data`, and the live API returns it under
`steps[].content[]` with `type: "audio"`. Nothing in the project could have
caught that, because the fake and the client shared the same wrong assumption.

## What was captured

| File | Request |
|---|---|
| `pixabay-search.json` | `GET pixabay.com/api/?q=umbrella+rain+street&per_page=5` |
| `openverse-search.json` | `GET api.openverse.org/v1/images/?q=umbrella+rain+street&page_size=3` |
| `unsplash-search.json` | `GET api.unsplash.com/search/photos?query=umbrella+rain+street&per_page=3` |
| `gemini-sentence-interaction.json` | `POST /v1beta/interactions`, `gemini-3.5-flash-lite`, input `borrowed` |
| `gemini-tts-interaction.json` | `POST /v1beta/interactions`, `gemini-3.1-flash-tts-preview`, input `Hola` |

## The two edits

Both are deliberate, and nothing else was touched:

1. **The Pixabay API key was removed from the recorded request URL.** The
   response bodies never carried credentials; the URL did.
2. **The audio payload was truncated.** `Hola` is 1.04 seconds, which is 66 KB
   of base64 — noise in a diff, forever. It is cut to 2,048 characters, aligned
   to four so it is still valid base64 and still decodes to an even number of
   bytes, which is what 16-bit PCM requires. Every other field, including
   `sample_rate`, `channels` and `mime_type`, is exactly as it arrived.

## Re-recording them

When a service changes shape, capture it again rather than editing these by
hand — an edited fixture is a hand-written fake with extra steps.

```
php artisan flashai:smoke "umbrella rain street" --keep
```

`MediaProviderTest::test_the_recorded_gemini_reply_still_carries_audio_where_we_read_it`
is the guard: if a re-recording moves the audio somewhere else, it fails here
rather than in production.
