<?php

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\ExceptionType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Appointment;
use App\Models\HealthInsurance;
use App\Models\Holiday;
use App\Models\MedicalEntry;
use App\Models\MedicalRecord;
use App\Models\Nationality;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Professional;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Setting;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

it('defines the DBML Eloquent relationships', function (string $model, string $method, string $relation, string $related) {
    $relationship = (new $model)->{$method}();

    expect($relationship)->toBeInstanceOf($relation)
        ->and($relationship->getRelated())->toBeInstanceOf($related);
})->with([
    [Role::class, 'users', BelongsToMany::class, User::class],
    [Role::class, 'permissions', BelongsToMany::class, Permission::class],
    [Permission::class, 'roles', BelongsToMany::class, Role::class],
    [User::class, 'roles', BelongsToMany::class, Role::class],
    [User::class, 'professional', HasOne::class, Professional::class],
    [User::class, 'createdAppointments', HasMany::class, Appointment::class],
    [User::class, 'cancelledAppointments', HasMany::class, Appointment::class],
    [User::class, 'pushSubscriptions', HasMany::class, PushSubscription::class],
    [Nationality::class, 'patients', HasMany::class, Patient::class],
    [HealthInsurance::class, 'patients', HasMany::class, Patient::class],
    [Patient::class, 'nationality', BelongsTo::class, Nationality::class],
    [Patient::class, 'healthInsurance', BelongsTo::class, HealthInsurance::class],
    [Patient::class, 'medicalRecord', HasOne::class, MedicalRecord::class],
    [Patient::class, 'appointments', HasMany::class, Appointment::class],
    [Professional::class, 'user', BelongsTo::class, User::class],
    [Professional::class, 'specialties', BelongsToMany::class, Specialty::class],
    [Professional::class, 'schedules', HasMany::class, Schedule::class],
    [Professional::class, 'scheduleExceptions', HasMany::class, ScheduleException::class],
    [Professional::class, 'appointments', HasMany::class, Appointment::class],
    [Professional::class, 'medicalEntries', HasMany::class, MedicalEntry::class],
    [Specialty::class, 'professionals', BelongsToMany::class, Professional::class],
    [Specialty::class, 'schedules', HasMany::class, Schedule::class],
    [Specialty::class, 'appointments', HasMany::class, Appointment::class],
    [Room::class, 'schedules', HasMany::class, Schedule::class],
    [Room::class, 'appointments', HasMany::class, Appointment::class],
    [MedicalRecord::class, 'patient', BelongsTo::class, Patient::class],
    [MedicalRecord::class, 'medicalEntries', HasMany::class, MedicalEntry::class],
    [MedicalEntry::class, 'medicalRecord', BelongsTo::class, MedicalRecord::class],
    [MedicalEntry::class, 'appointment', BelongsTo::class, Appointment::class],
    [MedicalEntry::class, 'professional', BelongsTo::class, Professional::class],
    [Schedule::class, 'professional', BelongsTo::class, Professional::class],
    [Schedule::class, 'specialty', BelongsTo::class, Specialty::class],
    [Schedule::class, 'room', BelongsTo::class, Room::class],
    [ScheduleException::class, 'professional', BelongsTo::class, Professional::class],
    [Appointment::class, 'patient', BelongsTo::class, Patient::class],
    [Appointment::class, 'professional', BelongsTo::class, Professional::class],
    [Appointment::class, 'specialty', BelongsTo::class, Specialty::class],
    [Appointment::class, 'room', BelongsTo::class, Room::class],
    [Appointment::class, 'creator', BelongsTo::class, User::class],
    [Appointment::class, 'canceller', BelongsTo::class, User::class],
    [Appointment::class, 'medicalEntry', HasOne::class, MedicalEntry::class],
    [Appointment::class, 'notifications', HasMany::class, Notification::class],
    [Notification::class, 'appointment', BelongsTo::class, Appointment::class],
    [PushSubscription::class, 'user', BelongsTo::class, User::class],
]);

it('defines notification polymorphism and stable morph aliases', function () {
    expect((new Notification)->notifiable())->toBeInstanceOf(MorphTo::class)
        ->and((new NotificationPreference)->notifiable())->toBeInstanceOf(MorphTo::class)
        ->and((new User)->notifications())->toBeInstanceOf(MorphMany::class)
        ->and((new Patient)->notifications())->toBeInstanceOf(MorphMany::class)
        ->and((new User)->notificationPreferences())->toBeInstanceOf(MorphMany::class)
        ->and((new Patient)->notificationPreferences())->toBeInstanceOf(MorphMany::class)
        ->and(Relation::morphMap())->toMatchArray([
            'users' => User::class,
            'patients' => Patient::class,
        ]);
});

it('persists stable morph aliases for users and patients', function () {
    $user = User::factory()->create();
    $patient = Patient::factory()->create();

    $userNotification = Notification::factory()->for($user, 'notifiable')->create();
    $patientPreference = NotificationPreference::factory()->for($patient, 'notifiable')->create();

    expect($userNotification->notifiable_type)->toBe('users')
        ->and($patientPreference->notifiable_type)->toBe('patients')
        ->and($user->notifications()->find($userNotification->id))->not->toBeNull()
        ->and($patient->notificationPreferences()->find($patientPreference->id))->not->toBeNull();
});

it('casts DBML enums and scalar values', function (string $model, array $casts) {
    expect((new $model)->getCasts())->toMatchArray($casts);
})->with([
    [Setting::class, ['cancellation_window_minutes' => 'integer', 'cancellation_penalty_days' => 'integer', 'reminder_lead_minutes' => 'integer', 'no_show_alert_threshold' => 'integer']],
    [User::class, ['is_active' => 'boolean']],
    [Role::class, ['is_system' => 'boolean']],
    [Patient::class, ['birth_date' => 'date', 'blocked_until' => 'datetime', 'is_active' => 'boolean']],
    [Holiday::class, ['date' => 'date', 'is_nationwide' => 'boolean']],
    [MedicalRecord::class, ['opened_at' => 'datetime']],
    [MedicalEntry::class, ['recorded_at' => 'datetime']],
    [Schedule::class, ['weekday' => 'integer', 'slot_duration_minutes' => 'integer', 'valid_from' => 'date', 'valid_until' => 'date']],
    [ScheduleException::class, ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'type' => ExceptionType::class]],
    [Appointment::class, ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'status' => AppointmentStatus::class, 'source' => AppointmentSource::class, 'is_penalized' => 'boolean', 'attended_at' => 'datetime']],
    [Notification::class, ['type' => NotificationType::class, 'channel' => NotificationChannel::class, 'status' => NotificationStatus::class, 'scheduled_for' => 'datetime', 'sent_at' => 'datetime', 'read_at' => 'datetime', 'attempts' => 'integer']],
    [NotificationPreference::class, ['type' => NotificationType::class, 'channel' => NotificationChannel::class, 'is_enabled' => 'boolean']],
    [PushSubscription::class, ['last_success_at' => 'datetime']],
]);

it('exposes the DBML defaults on new models', function (string $model, array $defaults) {
    expect((new $model)->getAttributes())->toMatchArray($defaults);
})->with([
    [Setting::class, ['timezone' => 'America/Argentina/Buenos_Aires']],
    [Role::class, ['is_system' => false]],
    [User::class, ['is_active' => true]],
    [Patient::class, ['is_active' => true]],
    [Holiday::class, ['is_nationwide' => true]],
    [Appointment::class, ['status' => 'booked', 'is_penalized' => false]],
    [Notification::class, ['status' => 'pending', 'attempts' => 0]],
    [NotificationPreference::class, ['is_enabled' => true]],
]);
