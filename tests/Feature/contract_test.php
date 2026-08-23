<?php
declare(strict_types=1);
it('passes the provider contract', function (): void { expect(shell_exec('php ' . escapeshellarg(__DIR__ . '/../run.php')))->toContain('PASS'); });
