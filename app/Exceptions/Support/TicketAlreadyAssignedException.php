<?php

namespace App\Exceptions\Support;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TicketAlreadyAssignedException extends Exception
{
    public function __construct(
        string $message = 'Ticket is already assigned to another staff member.',
    ) {
        parent::__construct($message, 409);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'ticket_already_assigned',
                'message' => $this->getMessage(),
            ],
        ], 409)->header('Cache-Control', 'no-store, private');
    }
}
