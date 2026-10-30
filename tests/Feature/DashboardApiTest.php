<?php

use App\Models\Patient;
use App\Models\Professional;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SchedulingSeeder;

const SUMMARY_URL = '/api/v1/reports/dashboard-summary';

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, CatalogSeeder::class, SchedulingSeeder::class]);
});

function actorWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

function summaryAs(User $user): array
{
    return test()->actingAs($user, 'sanctum')->getJson(SUMMARY_URL)->assertOk()->json('data');
}

describe('resumen del panel', function (): void {
    it('devuelve los indicadores del día', function (): void {
        $data = summaryAs(actorWithRole('reception'));

        expect($data['kpis'])->toHaveKeys([
            'today_total',
            'today_confirmed',
            'today_pending',
            'published_slots',
            'occupancy_rate',
            'absence_rate',
            'cancelled_last_week',
        ]);
        expect($data['kpis']['today_total'])->toBeGreaterThan(0);
        expect($data['kpis']['published_slots'])->toBeGreaterThan(0);
    });

    it('la ocupación de hoy no supera las franjas publicadas', function (): void {
        $kpis = summaryAs(actorWithRole('admin'))['kpis'];

        expect($kpis['today_total'])->toBeLessThanOrEqual($kpis['published_slots']);
        expect($kpis['occupancy_rate'])->toBeLessThanOrEqual(100);
    });

    it('lista a lo sumo diez turnos de hoy, ordenados por hora', function (): void {
        $rows = summaryAs(actorWithRole('reception'))['today_appointments'];

        expect(count($rows))->toBeLessThanOrEqual(10);
        expect(collect($rows)->pluck('time')->all())
            ->toBe(collect($rows)->pluck('time')->sort()->values()->all());
        expect($rows[0])->toHaveKeys(['time', 'patient', 'professional', 'specialty', 'status']);
    });

    it('informa ocupación por profesional y demanda por especialidad', function (): void {
        $data = summaryAs(actorWithRole('admin'));

        expect($data['occupancy'])->not->toBeEmpty();
        expect($data['occupancy'][0])->toHaveKeys(['professional', 'rate']);
        // Viene ordenada de mayor a menor.
        expect(collect($data['occupancy'])->pluck('rate')->all())
            ->toBe(collect($data['occupancy'])->pluck('rate')->sortDesc()->values()->all());

        expect($data['demand'])->not->toBeEmpty();
        expect($data['demand'][0])->toHaveKeys(['specialty', 'count']);
    });

    it('avisa de la ausencia declarada para los próximos días', function (): void {
        $alerts = summaryAs(actorWithRole('admin'))['alerts'];

        expect(collect($alerts)->pluck('title'))->toContain('Ausencia declarada');
    });

    it('expone el umbral de ausentismo configurado', function (): void {
        $data = summaryAs(actorWithRole('admin'));

        expect($data['no_show_threshold'])->toBe(8);
    });
});

describe('alcance por rol', function (): void {
    it('el profesional ve sólo su propia agenda', function (): void {
        $professional = Professional::query()->with('user')->first();
        $actor = $professional->user;

        $data = summaryAs($actor);

        expect($data['scope'])->toBe($actor->name);
        expect($data['occupancy'])->toHaveCount(1);
        expect($data['occupancy'][0]['professional'])->toBe($actor->name);
    });

    it('recepción ve el centro completo', function (): void {
        $data = summaryAs(actorWithRole('reception'));

        expect($data['scope'])->toBeNull();
        expect(count($data['occupancy']))->toBeGreaterThan(1);
    });
});

describe('control de acceso', function (): void {
    it('rechaza al paciente', function (): void {
        $patient = Patient::factory()->create();

        test()->actingAs($patient, 'sanctum')->getJson(SUMMARY_URL)->assertForbidden();
    });

    it('rechaza sin sesión', function (): void {
        test()->getJson(SUMMARY_URL)->assertUnauthorized();
    });
});
