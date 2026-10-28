<?php

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\ExceptionType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;

it('defines the exact DBML enum values', function (string $enum, array $values) {
    expect(array_column($enum::cases(), 'value'))->toBe($values);
})->with([
    'appointment status' => [AppointmentStatus::class, ['booked', 'confirmed', 'cancelled_by_professional', 'cancelled_by_center', 'no_show', 'attended']],
    'appointment source' => [AppointmentSource::class, ['portal', 'desk']],
    'exception type' => [ExceptionType::class, ['absence', 'block']],
    'notification type' => [NotificationType::class, ['booking_confirmation', 'reminder', 'cancellation', 'reschedule', 'professional_news', 'early_alert']],
    'notification channel' => [NotificationChannel::class, ['email', 'push']],
    'notification status' => [NotificationStatus::class, ['pending', 'queued', 'sent', 'failed']],
]);
