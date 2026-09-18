<?php

namespace App\Enums;

enum NotificationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    /**
     * Finished without sending: the hold cleared while the message waited,
     * so sending it would have told the customer something no longer true.
     * The integration event is processed alongside it - the queue did its
     * job by deciding no send was owed.
     */
    case Expired = 'expired';
}
