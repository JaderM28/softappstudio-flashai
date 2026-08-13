<?php

namespace App\Http\Requests;

class UpdateNoteRequest extends StoreNoteRequest
{
    // Same shape as creating one; authorisation is handled by NotePolicy on the
    // route-bound note.
}
