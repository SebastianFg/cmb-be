<?php

namespace App\Enums;

enum NotificationType: string
{
    case BookingConfirmation = 'booking_confirmation';
    case Reminder = 'reminder';
    case Cancellation = 'cancellation';
    case Reschedule = 'reschedule';
    case ProfessionalNews = 'professional_news';
    case EarlyAlert = 'early_alert';
}
