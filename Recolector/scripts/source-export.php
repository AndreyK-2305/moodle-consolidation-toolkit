<?php
// Orquestador CLI portable del recolector de origen.

declare(strict_types=1);

function export_arg(string $name, ?string $default = null): ?string {
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv ?? [] as $argument) {
        if (str_starts_with((string)$argument, $prefix)) {
            return substr((string)$argument, strlen($prefix));
        }
    }
    return $default;
}

function export_run(array $arguments): void {
    $parts = [escapeshellarg(PHP_BINARY)];
    foreach ($arguments as $argument) {
        $parts[] = escapeshellarg((string)$argument);
    }
    $command = implode(' ', $parts);
    passthru($command, $exitcode);
    if ($exitcode !== 0) {
        throw new RuntimeException(
            'Un subproceso del recolector terminó con código ' . $exitcode . '.'
        );
    }
}

try {
    $config = trim((string)export_arg(
        'config',
        (string)(getenv('MOODLE_CONFIG_PATH') ?: '/var/www/html/config.php')
    ));
    $sourceid = strtolower(trim((string)export_arg('sourceid', '')));
    $sourcename = trim((string)export_arg('sourcename', ''));
    $outputdir = rtrim(trim((string)export_arg('outputdir', '')), '/\\');
    $outputzip = trim((string)export_arg('outputzip', ''));
    $scope = strtolower(trim((string)export_arg('scope', 'all')));
    $trustoauth = (int)export_arg('trustoauthusernameassub', '0');
    if (!is_readable($config) ||
            !preg_match('/^[a-z][a-z0-9_-]*$/', $sourceid) ||
            $sourcename === '' ||
            $outputdir === '' ||
            $outputzip === '' ||
            !in_array($scope, ['lab', 'all'], true) ||
            !in_array($trustoauth, [0, 1], true)) {
        throw new RuntimeException(
            'Use --config, --sourceid, --sourcename, --outputdir y --outputzip válidos.'
        );
    }
    if (!is_dir($outputdir) &&
            !mkdir($outputdir, 0770, true) &&
            !is_dir($outputdir)) {
        throw new RuntimeException('No fue posible crear ' . $outputdir . '.');
    }

    $scripts = __DIR__;
    $contractsha = hash(
        'sha256',
        'moodle-consolidation-source|1.0|' . $sourceid . '|' . $sourcename
    );
    export_run([
        $scripts . '/extract-identities.php',
        '--config=' . $config,
        '--source=' . $sourceid,
        '--scope=' . $scope,
        '--output=' . $outputdir . '/identidades.json',
        '--trustoauthusernameassub=' . $trustoauth,
    ]);
    export_run([
        $scripts . '/phase6-inventory.php',
        '--config=' . $config,
        '--output=' . $outputdir . '/inventario-origen.json',
        '--configsha=' . $contractsha,
        '--sourceid=' . $sourceid,
        '--sourcename=' . $sourcename,
    ]);
    export_run([
        $scripts . '/source-plugins.php',
        '--config=' . $config,
        '--output=' . $outputdir . '/plugins.json',
        '--sourceid=' . $sourceid,
    ]);

    $inventory = json_decode(
        (string)file_get_contents($outputdir . '/inventario-origen.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $courses = $inventory['courses'] ?? [];
    $position = 0;
    foreach ($courses as $course) {
        $position++;
        $courseid = (int)($course['source_course_id'] ?? 0);
        fwrite(
            STDOUT,
            '[' . $position . '/' . count($courses) . '] ' .
            $sourceid . ': ' . (string)($course['shortname'] ?? $courseid) .
            PHP_EOL
        );
        export_run([
            $scripts . '/source-backup-course.php',
            '--config=' . $config,
            '--outputdir=' . $outputdir,
            '--sourceid=' . $sourceid,
            '--courseid=' . $courseid,
        ]);
    }
    export_run([
        $scripts . '/source-seal.php',
        '--inputdir=' . $outputdir,
        '--outputzip=' . $outputzip,
        '--sourceid=' . $sourceid,
    ]);
} catch (Throwable $error) {
    fwrite(STDERR, 'SOURCE_EXPORT_ERROR ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

