<?php

use Illuminate\Support\Facades\Config;

/**
 * El frontend vive en otro dominio, así que sin estos encabezados el navegador
 * descarta la respuesta aunque la API conteste 200.
 */
function corsHeaderFor(string $origin): ?string
{
    return test()->getJson('/api/v1/health', ['Origin' => $origin])
        ->headers->get('Access-Control-Allow-Origin');
}

it('deja pasar a localhost sin configurar nada', function (): void {
    Config::set('cors.allowed_origins', []);

    expect(corsHeaderFor('http://localhost:3000'))->toBe('http://localhost:3000');
});

it('deja pasar al sitio publicado que declara CORS_ALLOWED_ORIGINS', function (): void {
    Config::set('cors.allowed_origins', ['https://turnos.example.com']);

    expect(corsHeaderFor('https://turnos.example.com'))->toBe('https://turnos.example.com');
});

it('rechaza un origen que no está declarado', function (): void {
    Config::set('cors.allowed_origins', ['https://turnos.example.com']);

    expect(corsHeaderFor('https://otro.example.com'))->toBeNull();
});
