<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    /** Delivery row created, not yet handed to FCM. */
    case Pending = 'pending';

    /** Accepted by FCM for delivery to the device. */
    case Sent = 'sent';

    /** FCM rejected the message. */
    case Failed = 'failed';

    /** The app confirmed it received and processed the event. */
    case Acknowledged = 'acknowledged';
}
