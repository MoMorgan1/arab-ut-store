<?php

namespace App\Enums\Chat;

enum ChatConversationCloseReason: string
{
    case CustomerStartedNew = 'customer_started_new';
    case Inactive = 'inactive';
    case SupersededByLoginClaim = 'superseded_by_login_claim';
    case InvariantUpgradeDuplicate = 'invariant_upgrade_duplicate';
}
