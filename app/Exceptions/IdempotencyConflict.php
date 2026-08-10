<?php

namespace App\Exceptions;

use RuntimeException;

final class IdempotencyConflict extends RuntimeException {}
