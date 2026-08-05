<?php
// Notificación SMTP opcional y no bloqueante para exportación y validación.

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

function smtp_arg(string $name, string $default = ''): string {
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv ?? [] as $argument) {
        if (str_starts_with((string)$argument, $prefix)) {
            return substr((string)$argument, strlen($prefix));
        }
    }
    return $default;
}

function smtp_warning(string $message): never {
    fwrite(STDERR, 'SMTP_WARNING ' . $message . PHP_EOL);
    exit(0);
}

function smtp_load_phpmailer(string $moodleconfig): void {
    $moodleroot = dirname((string)(realpath($moodleconfig) ?: $moodleconfig));
    $autoloaders = [
        __DIR__ . '/../vendor/autoload.php',
        $moodleroot . '/vendor/autoload.php',
    ];
    foreach ($autoloaders as $autoloader) {
        if (is_readable($autoloader)) {
            require_once $autoloader;
            if (class_exists(PHPMailer::class)) {
                return;
            }
        }
    }

    $library = $moodleroot . '/lib/phpmailer/src';
    foreach (['Exception.php', 'SMTP.php', 'PHPMailer.php'] as $filename) {
        $path = $library . '/' . $filename;
        if (!is_readable($path)) {
            smtp_warning(
                'No se encontró PHPMailer dentro de Moodle: ' . $library . '.'
            );
        }
        require_once $path;
    }
    if (!class_exists(PHPMailer::class)) {
        smtp_warning('PHPMailer no quedó disponible después de cargar Moodle.');
    }
}

try {
    $smtpconfig = trim(smtp_arg('smtpconfig'));
    if ($smtpconfig === '' || !is_readable($smtpconfig)) {
        exit(0);
    }
    if ((int)filesize($smtpconfig) > 65536) {
        smtp_warning('smtp-config.json supera el tamaño permitido.');
    }
    $config = json_decode(
        (string)file_get_contents($smtpconfig),
        true,
        64,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($config) || ($config['enabled'] ?? false) !== true) {
        exit(0);
    }

    $moodleconfig = trim(smtp_arg('moodleconfig'));
    $sourceid = trim(smtp_arg('sourceid'));
    $operation = strtolower(trim(smtp_arg('operation', 'export')));
    $result = strtolower(trim(smtp_arg('result')));
    $exitcode = (int)smtp_arg('exitcode', '1');
    $stage = trim(smtp_arg('stage', 'unknown'));
    $duration = max(0, (int)smtp_arg('duration', '0'));
    $outputzip = trim(smtp_arg('outputzip'));
    $reportfile = trim(smtp_arg('reportfile'));
    $logfile = trim(smtp_arg('logfile'));
    if (!is_readable($moodleconfig) ||
            !preg_match('/^[a-z][a-z0-9_-]{0,62}$/', $sourceid) ||
            !in_array($operation, ['export', 'validation'], true) ||
            !in_array($result, ['success', 'error'], true)) {
        smtp_warning('Los datos de la notificación son inválidos.');
    }

    $host = trim((string)($config['host'] ?? ''));
    $port = (int)($config['port'] ?? 0);
    $encryption = strtolower(trim((string)($config['encryption'] ?? 'tls')));
    $authentication = ($config['auth'] ?? true) === true;
    $username = (string)($config['username'] ?? '');
    $password = (string)($config['password'] ?? '');
    $fromemail = trim((string)($config['from_email'] ?? ''));
    $fromname = trim((string)($config['from_name'] ?? 'Recolector Moodle'));
    $timeout = (int)($config['timeout_seconds'] ?? 10);
    $recipients = $config['to'] ?? [];
    if (!is_array($recipients)) {
        $recipients = [$recipients];
    }
    if ($host === '' || $port < 1 || $port > 65535 ||
            !in_array($encryption, ['tls', 'ssl', 'none'], true) ||
            !filter_var($fromemail, FILTER_VALIDATE_EMAIL) ||
            $timeout < 3 || $timeout > 30 ||
            ($authentication && ($username === '' || $password === ''))) {
        smtp_warning('La configuración SMTP está incompleta o es inválida.');
    }

    $validrecipients = [];
    foreach ($recipients as $recipient) {
        $recipient = trim((string)$recipient);
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $validrecipients[] = $recipient;
        }
    }
    if ($validrecipients === []) {
        smtp_warning('No hay destinatarios SMTP válidos.');
    }

    smtp_load_phpmailer($moodleconfig);
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = $port;
    $mail->Timeout = $timeout;
    $mail->SMTPAuth = $authentication;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->CharSet = 'UTF-8';
    if ($encryption === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }
    $mail->setFrom($fromemail, $fromname);
    foreach ($validrecipients as $recipient) {
        $mail->addAddress($recipient);
    }

    if ($operation === 'validation') {
        $label = $result === 'success' ? 'VALIDACIÓN OK' : 'VALIDACIÓN ERROR';
        $operationlabel = 'Validación exhaustiva';
    } else {
        $label = $result === 'success' ? 'COMPLETADO' : 'ERROR';
        $operationlabel = 'Exportación de origen';
    }
    $mail->Subject = '[Recolector Moodle] ' . $label . ' - ' . $sourceid;
    $mail->isHTML(false);
    $bodylines = [
        'Resultado: ' . $label,
        'Operación: ' . $operationlabel,
        'Origen: ' . $sourceid,
        'Etapa: ' . $stage,
        'Código de salida: ' . $exitcode,
        'Duración: ' . $duration . ' segundos',
        'ZIP: ' . $outputzip,
    ];
    if ($operation === 'validation' && $reportfile !== '') {
        $bodylines[] = 'Reporte: ' . $reportfile;
    }
    $bodylines[] = 'Log: ' . $logfile;
    $bodylines[] = 'Fecha UTC: ' . gmdate('c');
    $mail->Body = implode(PHP_EOL, $bodylines);
    $mail->send();
    $operationoutput = $operation === 'validation' ? ' operation=validation' : '';
    fwrite(
        STDOUT,
        'SMTP_OK' . $operationoutput . ' result=' . $result .
        ' source=' . $sourceid . PHP_EOL
    );
} catch (Throwable $error) {
    smtp_warning($error->getMessage());
}
