<?php

namespace App\Enums;

enum WalletEntryType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Refund = 'refund';
    case Adjustment = 'adjustment';
}
