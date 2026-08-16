<?php

namespace App\Enums;

/**
 * Why a third-party call did not produce what was asked for.
 *
 * This exists so that the decision to retry is made on facts the app recorded
 * rather than on text the provider wrote. It used to be the latter: a substring
 * search for "HTTP 429" over a message that carries five hundred characters of
 * the provider's own response body. An error page that happened to mention that
 * number would have been retried forever, and a provider that reworded its
 * errors would have stopped being retried at all — silently, in both directions.
 */
enum FailureReason: string
{
    /** No key, no token, nothing to call with. */
    case NotConfigured = 'not_configured';

    /** The network never got there. */
    case Unreachable = 'unreachable';

    /** It answered, and the answer was an error status. */
    case Rejected = 'rejected';

    /** It answered normally and had nothing to give. */
    case NothingFound = 'nothing_found';

    /** It answered with something that could not be used. */
    case Unusable = 'unusable';
}
