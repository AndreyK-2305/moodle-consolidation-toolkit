<?php
// Recolector v7: genera un backup de curso y su inventario reanudable.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/bootstrap.php');
require(collector_moodle_config_path());
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/phase5-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'config' => null,
        'outputdir' => null,
        'sourceid' => null,
        'courseid' => null,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php source-backup-course.php --outputdir=RUTA " .
        "--sourceid=virtual --courseid=2 --config=/ruta/config.php\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

/**
 * Llave estable compatible con el motor de la fase 6.
 */
function collector_course_key(string $sourceid, int $courseid): string {
    $source = strtoupper((string)preg_replace('/[^a-z0-9_-]+/i', '-', $sourceid));
    $token = strtoupper(substr(
        hash('sha256', $sourceid . '|course|' . $courseid),
        0,
        12
    ));
    return 'COURSE-' . $source . '-' . $token;
}

/**
 * Hash canónico suficiente para detectar cambios entre reintentos.
 */
function collector_value_sha256(mixed $value): string {
    if (is_array($value)) {
        if (array_is_list($value)) {
            $value = array_map('collector_value_sha256_value', $value);
        } else {
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = collector_value_sha256_value($item);
            }
        }
    }
    return hash('sha256', json_encode(
        $value,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRESERVE_ZERO_FRACTION |
        JSON_THROW_ON_ERROR
    ));
}

function collector_value_sha256_value(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('collector_value_sha256_value', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = collector_value_sha256_value($item);
    }
    return $value;
}

/**
 * Metadatos baratos usados para reutilizar un SHA ya calculado.
 *
 * @return array{bytes:int,mtime:int}
 */
function collector_artifact_metadata(string $path, string $label): array {
    clearstatcache(true, $path);
    if (!is_file($path) || is_link($path) || !is_readable($path)) {
        throw new RuntimeException($label . ' no existe o no es un archivo regular.');
    }
    $bytes = filesize($path);
    $mtime = filemtime($path);
    if ($bytes === false || $bytes < 1 || $mtime === false) {
        throw new RuntimeException($label . ' no conserva metadatos válidos.');
    }
    return ['bytes' => (int)$bytes, 'mtime' => (int)$mtime];
}

function collector_is_sha256(mixed $value): bool {
    return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
}

try {
    $outputdir = rtrim(trim((string)$options['outputdir']), '/\\');
    $sourceid = core_text::strtolower(trim((string)$options['sourceid']));
    $courseid = (int)$options['courseid'];
    if ($outputdir === '' ||
            !preg_match('/^[a-z][a-z0-9_-]*$/', $sourceid) ||
            $courseid < 1 ||
            $courseid === SITEID) {
        throw new RuntimeException('outputdir, sourceid o courseid inválido.');
    }
    $course = $DB->get_record(
        'course',
        ['id' => $courseid],
        'id,category,fullname,shortname,idnumber',
        MUST_EXIST
    );
    $coursekey = collector_course_key($sourceid, $courseid);
    $basename = core_text::strtolower($coursekey);
    $directories = [
        'courses' => $outputdir . '/cursos',
        'inventories' => $outputdir . '/inventarios',
        'checkpoints' => $outputdir . '/checkpoints',
    ];
    foreach ($directories as $directory) {
        if (!is_dir($directory) &&
                !mkdir($directory, 0770, true) &&
                !is_dir($directory)) {
            throw new RuntimeException('No fue posible crear ' . $directory . '.');
        }
    }

    $backuppath = $directories['courses'] . '/' . $basename . '.mbz';
    $inventorypath =
        $directories['inventories'] . '/inventory-' . $basename . '.json';
    $checkpointpath =
        $directories['checkpoints'] . '/checkpoint-' . $basename . '.json';

    $inventory = p5_collect_course_inventory($courseid);
    $statehash = collector_value_sha256($inventory);

    if (is_readable($checkpointpath)) {
        $checkpoint = p5_read_json($checkpointpath);
        $backupmeta = collector_artifact_metadata($backuppath, 'El backup');
        $inventorymeta = collector_artifact_metadata(
            $inventorypath,
            'El inventario del curso'
        );
        if (($checkpoint['schema_version'] ?? '') !== '1.0' ||
                ($checkpoint['package_type'] ?? '') !==
                    'moodle-consolidation-source-course' ||
                ($checkpoint['source_id'] ?? '') !== $sourceid ||
                ($checkpoint['course_key'] ?? '') !== $coursekey ||
                (int)($checkpoint['source_course_id'] ?? 0) !== $courseid ||
                ($checkpoint['source_state_sha256'] ?? '') !== $statehash ||
                !collector_is_sha256($checkpoint['backup_sha256'] ?? null) ||
                !collector_is_sha256($checkpoint['inventory_sha256'] ?? null) ||
                (int)($checkpoint['backup_bytes'] ?? -1) !==
                    $backupmeta['bytes'] ||
                ($checkpoint['status'] ?? '') !== 'prepared') {
            throw new RuntimeException(
                'El checkpoint existente no coincide con el curso actual.'
            );
        }

        $hasfastmetadata =
            array_key_exists('backup_mtime', $checkpoint) &&
            array_key_exists('inventory_bytes', $checkpoint) &&
            array_key_exists('inventory_mtime', $checkpoint);
        if ($hasfastmetadata) {
            if ((int)$checkpoint['backup_mtime'] !== $backupmeta['mtime'] ||
                    (int)$checkpoint['inventory_bytes'] !==
                        $inventorymeta['bytes'] ||
                    (int)$checkpoint['inventory_mtime'] !==
                        $inventorymeta['mtime']) {
                throw new RuntimeException(
                    'Los artefactos cambiaron después de crear el checkpoint.'
                );
            }
        } else {
            $backupsha = hash_file('sha256', $backuppath);
            $inventorysha = hash_file('sha256', $inventorypath);
            if ($backupsha === false || $inventorysha === false ||
                    !hash_equals($checkpoint['backup_sha256'], $backupsha) ||
                    !hash_equals($checkpoint['inventory_sha256'], $inventorysha)) {
                throw new RuntimeException(
                    'El checkpoint anterior perdió integridad durante la migración.'
                );
            }
            $checkpoint['collector_version'] = '7.2.0-linux-rc2';
            $checkpoint['checkpoint_updated_at_utc'] = gmdate('c');
            $checkpoint['backup_bytes'] = $backupmeta['bytes'];
            $checkpoint['backup_mtime'] = $backupmeta['mtime'];
            $checkpoint['inventory_bytes'] = $inventorymeta['bytes'];
            $checkpoint['inventory_mtime'] = $inventorymeta['mtime'];
            $checkpoint['hash_strategy'] = 'single-pass-v1';
            p5_write_json($checkpointpath, $checkpoint);
        }
        cli_writeln(
            'SOURCE_COURSE_BACKUP_OK source=' . $sourceid .
            ' course_key=' . $coursekey .
            ' course_id=' . $courseid .
            ' status=reused'
        );
        exit(0);
    }

    if (is_file($backuppath) || is_file($inventorypath)) {
        throw new RuntimeException(
            'Existen artefactos parciales sin checkpoint para ' . $coursekey . '.'
        );
    }

    $document = [
        'schema_version' => '1.0',
        'package_type' => 'moodle-consolidation-course-inventory',
        'generated_at_utc' => gmdate('c'),
        'source_id' => $sourceid,
        'course_key' => $coursekey,
        'source_state_sha256' => $statehash,
        'inventory' => $inventory,
        'write_performed' => false,
    ];
    p5_write_json($inventorypath, $document);

    try {
        $inventorymeta = collector_artifact_metadata(
            $inventorypath,
            'El inventario del curso'
        );
        $inventorysha = hash_file('sha256', $inventorypath);
        if ($inventorysha === false) {
            throw new RuntimeException(
                'No fue posible calcular SHA-256 del inventario del curso.'
            );
        }
        $backupmeta = p5_create_course_backup($courseid, $backuppath);
        $checkpoint = [
            'schema_version' => '1.0',
            'package_type' => 'moodle-consolidation-source-course',
            'collector_version' => '7.2.0-linux-rc2',
            'generated_at_utc' => gmdate('c'),
            'source_id' => $sourceid,
            'course_key' => $coursekey,
            'source_course_id' => $courseid,
            'source_course_idnumber' => (string)$course->idnumber,
            'source_shortname' => (string)$course->shortname,
            'source_state_sha256' => $statehash,
            'backup_file' => 'cursos/' . basename($backuppath),
            'backup_sha256' => $backupmeta['sha256'],
            'backup_bytes' => $backupmeta['bytes'],
            'backup_mtime' => $backupmeta['mtime'],
            'inventory_file' => 'inventarios/' . basename($inventorypath),
            'inventory_sha256' => $inventorysha,
            'inventory_bytes' => $inventorymeta['bytes'],
            'inventory_mtime' => $inventorymeta['mtime'],
            'hash_strategy' => 'single-pass-v1',
            'status' => 'prepared',
            'destination_write_performed' => false,
        ];
        p5_write_json($checkpointpath, $checkpoint);
    } catch (Throwable $error) {
        foreach ([$backuppath, $inventorypath] as $partial) {
            if (is_file($partial)) {
                @unlink($partial);
            }
        }
        throw $error;
    }

    cli_writeln(
        'SOURCE_COURSE_BACKUP_OK source=' . $sourceid .
        ' course_key=' . $coursekey .
        ' course_id=' . $courseid .
        ' status=created bytes=' . $backupmeta['bytes']
    );
} catch (Throwable $error) {
    cli_error('SOURCE_COURSE_BACKUP_ERROR ' . $error->getMessage());
}
