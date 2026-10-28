<?php

use Illuminate\Support\Facades\Schema;

it('contains the 23 DBML tables and their key columns', function () {
    $tables = [
        'settings' => ['id', 'center_name', 'timezone', 'cancellation_window_minutes', 'cancellation_penalty_days', 'reminder_lead_minutes', 'no_show_alert_threshold'],
        'roles' => ['id', 'name', 'description', 'is_system'],
        'permissions' => ['id', 'name', 'module', 'description'],
        'role_user' => ['role_id', 'user_id'],
        'permission_role' => ['permission_id', 'role_id'],
        'users' => ['id', 'name', 'email', 'google_id', 'is_active'],
        'nationalities' => ['id', 'name', 'iso_code'],
        'health_insurances' => ['id', 'name', 'plan'],
        'patients' => ['id', 'health_insurance_id', 'nationality_id', 'document_number', 'email', 'blocked_until', 'is_active'],
        'specialties' => ['id', 'name'],
        'professionals' => ['id', 'user_id', 'license_number'],
        'professional_specialty' => ['professional_id', 'specialty_id'],
        'rooms' => ['id', 'name', 'floor'],
        'holidays' => ['id', 'date', 'name', 'is_nationwide'],
        'medical_records' => ['id', 'patient_id', 'blood_type', 'background', 'opened_at'],
        'medical_entries' => ['id', 'medical_record_id', 'appointment_id', 'professional_id', 'recorded_at'],
        'schedules' => ['id', 'professional_id', 'specialty_id', 'room_id', 'weekday', 'start_time', 'end_time', 'slot_duration_minutes', 'valid_from', 'valid_until'],
        'schedule_exceptions' => ['id', 'professional_id', 'starts_at', 'ends_at', 'type', 'reason'],
        'appointments' => ['id', 'patient_id', 'professional_id', 'specialty_id', 'room_id', 'created_by', 'cancelled_by', 'starts_at', 'ends_at', 'status', 'source', 'confirmation_code', 'is_penalized'],
        'notifications' => ['id', 'notifiable_type', 'notifiable_id', 'appointment_id', 'type', 'channel', 'status', 'scheduled_for', 'attempts'],
        'notification_preferences' => ['id', 'notifiable_type', 'notifiable_id', 'type', 'channel', 'is_enabled'],
        'push_subscriptions' => ['id', 'user_id', 'endpoint', 'public_key', 'auth_token', 'last_success_at'],
        'audits' => ['id', 'actor_type', 'actor_id', 'auditable_type', 'auditable_id', 'event', 'old_values', 'new_values'],
    ];

    expect($tables)->toHaveCount(23);

    foreach ($tables as $table => $columns) {
        expect(Schema::hasTable($table))->toBeTrue("Missing table: {$table}");
        expect(Schema::hasColumns($table, $columns))->toBeTrue("Missing key columns in: {$table}");
    }
});

it('does not contain tables of the inherited domain', function () {
    $inheritedTables = [
        'candidates', 'catalogs', 'charges', 'circuits', 'claims', 'communication_method_urs',
        'configs', 'control_stages', 'departments', 'file_attachments', 'file_origins',
        'form_charges', 'forms', 'loader_strikes', 'municipalities', 'provinces',
        'regional_units', 'schools', 'seat_allocations', 'seat_calculations', 'slogans',
        'subslogan_charges', 'subslogans', 'superpowers', 'table_controls',
        'table_control_statuses', 'table_totals', 'type_form_charges',
        'type_form_municipalities', 'type_forms', 'user_controls', 'vote_load_values', 'vote_loads',
    ];

    foreach ($inheritedTables as $table) {
        expect(Schema::hasTable($table))->toBeFalse("Inherited table still exists: {$table}");
    }
});
