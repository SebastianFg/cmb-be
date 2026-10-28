<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NOTIFICATION_TYPES = [
        'booking_confirmation',
        'reminder',
        'cancellation',
        'reschedule',
        'professional_news',
        'early_alert',
    ];

    private const NOTIFICATION_CHANNELS = ['email', 'push'];

    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->foreignId('appointment_id')->nullable()->constrained();
            $table->enum('type', self::NOTIFICATION_TYPES);
            $table->enum('channel', self::NOTIFICATION_CHANNELS);
            $table->enum('status', ['pending', 'queued', 'sent', 'failed'])->default('pending');
            $table->timestampTz('scheduled_for');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
            $table->index(['notifiable_type', 'notifiable_id']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->enum('type', self::NOTIFICATION_TYPES);
            $table->enum('channel', self::NOTIFICATION_CHANNELS);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['notifiable_type', 'notifiable_id', 'type', 'channel']);
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->text('endpoint')->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('user_agent')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->string('event');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audits');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
