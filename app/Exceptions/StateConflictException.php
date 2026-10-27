<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A request that is valid on its own but conflicts with the current state.
 */
final class StateConflictException extends RuntimeException {}
