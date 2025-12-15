<?php

declare(strict_types=1);

it('returns empty maintenance notes when none are saved', function (): void {
    $res = $this->getJson('/api/v1/maintenance/notes');

    $res->assertOk()
        ->assertJsonPath('data.key', 'default')
        ->assertJsonPath('data.body', null);
});

it('can save maintenance notes', function (): void {
    $save = $this->putJson('/api/v1/maintenance/notes', [
        'body' => "Hello\nWorld",
    ]);

    $save->assertOk()
        ->assertJsonPath('data.key', 'default')
        ->assertJsonPath('data.body', "Hello\nWorld");

    $fetch = $this->getJson('/api/v1/maintenance/notes');
    $fetch->assertOk()
        ->assertJsonPath('data.body', "Hello\nWorld");
});

it('validates maintenance notes body type', function (): void {
    $res = $this->putJson('/api/v1/maintenance/notes', [
        'body' => 123,
    ]);

    $res->assertStatus(422);
});
