<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Booked = 'booked';
    case Confirmed = 'confirmed';
    case CancelledByProfessional = 'cancelled_by_professional';
    case CancelledByCenter = 'cancelled_by_center';
    case NoShow = 'no_show';
    case Attended = 'attended';
}
