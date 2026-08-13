<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Laravel no longer includes this by default, but the note and review
    // screens authorise route-bound models through policies.
    use AuthorizesRequests;
}
