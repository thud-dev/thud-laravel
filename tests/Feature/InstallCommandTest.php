<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->envDir = sys_get_temp_dir().'/thud-install-'.uniqid();
    mkdir($this->envDir);
    file_put_contents($this->envDir.'/.env', "APP_NAME=Shop\nTHUD_TOKEN=old\n");
    app()->useEnvironmentPath($this->envDir);
    pingAnswers(200, ['app' => 'shop', 'environment' => 'production']);
});

afterEach(function (): void {
    @unlink($this->envDir.'/.env');
    @rmdir($this->envDir);
});

it('writes the token to .env and tests the connection', function (): void {
    $this->artisan('thud:install', ['--token' => 'thd_new0123456789'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Connected as shop');

    expect(file_get_contents($this->envDir.'/.env'))->toBe("APP_NAME=Shop\nTHUD_TOKEN=thd_new0123456789\n");
});

it('rejects something that is not a Thud token', function (): void {
    $this->artisan('thud:install', ['--token' => 'sk_live_123'])->assertExitCode(1);

    expect(file_get_contents($this->envDir.'/.env'))->toContain('THUD_TOKEN=old');
});

it('rejects a token with characters a Thud token never has', function (): void {
    $this->artisan('thud:install', ['--token' => 'thd_abc$1\\x'])->assertExitCode(1);

    expect(file_get_contents($this->envDir.'/.env'))->toContain('THUD_TOKEN=old');
});
