<?php
// Fase 3: inventario no destructivo de identidades, roles y matriculas.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/bootstrap.php');
require(collector_moodle_config_path());
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/enrollib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'config' => null,
        'source' => null,
        'scope' => 'lab',
        'output' => null,
        'trustoauthusernameassub' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);

if ($options['help']) {
    cli_writeln(<<<TXT
Extrae identidades, roles y matriculas sin modificar Moodle.

Uso:
  php extract-identities.php --source=virtual --scope=lab \
      --output=/exports/phase3/identity-virtual.json \
      --config=/var/www/html/config.php

Opciones:
  --scope=lab|all                 lab usa usuarios [LAB-MIGRATION] y administradores.
  --trustoauthusernameassub=0|1   Solo activar si se verifico que el username
                                  externo del proveedor es realmente el sub.

TXT);
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

$source = (string)($options['source'] ?? '');
$scope = core_text::strtolower((string)$options['scope']);
$output = (string)($options['output'] ?? '');
$trustoauth = (bool)(int)$options['trustoauthusernameassub'];

if (!preg_match('/^[a-z][a-z0-9_-]*$/', $source)) {
    cli_error('El origen debe ser un identificador válido definido en config.yaml.');
}
if (!in_array($scope, ['lab', 'all'], true)) {
    cli_error('El scope debe ser lab o all.');
}
if ($output === '') {
    cli_error('Debe indicar --output.');
}

/**
 * Ejecuta una consulta por lotes de IDs para evitar limites de parametros.
 *
 * @param int[] $ids
 * @param callable $callback
 * @return array
 */
function lab3_by_chunks(array $ids, callable $callback): array {
    $result = [];
    foreach (array_chunk($ids, 400) as $chunk) {
        foreach ($callback($chunk) as $record) {
            $result[] = $record;
        }
    }
    return $result;
}

/**
 * Normaliza un issuer sin modificar su significado.
 */
function lab3_normalize_issuer(string $issuer): string {
    return rtrim(core_text::strtolower(trim($issuer)), '/');
}

/**
 * Describe un contexto de asignacion de rol con una clave auditable.
 */
function lab3_describe_context(
    stdClass $context,
    array $courses,
    array $categories,
    array $modules
): array {
    switch ((int)$context->contextlevel) {
        case CONTEXT_SYSTEM:
            return ['system', 'system', 'Sistema'];
        case CONTEXT_COURSECAT:
            $category = $categories[(int)$context->instanceid] ?? null;
            if ($category) {
                $key = trim((string)$category->idnumber) !== ''
                    ? 'category:' . $category->idnumber
                    : 'category-id:' . $category->id;
                return ['coursecat', $key, (string)$category->name];
            }
            return ['coursecat', 'category-id:' . $context->instanceid, 'Categoria no encontrada'];
        case CONTEXT_COURSE:
            $course = $courses[(int)$context->instanceid] ?? null;
            if ($course) {
                $key = trim((string)$course->idnumber) !== ''
                    ? 'course:' . $course->idnumber
                    : 'course-id:' . $course->id;
                return ['course', $key, (string)$course->fullname];
            }
            return ['course', 'course-id:' . $context->instanceid, 'Curso no encontrado'];
        case CONTEXT_MODULE:
            $module = $modules[(int)$context->instanceid] ?? null;
            if ($module) {
                $course = $courses[(int)$module->course] ?? null;
                $coursekey = $course && trim((string)$course->idnumber) !== ''
                    ? (string)$course->idnumber
                    : 'course-id-' . $module->course;
                $modulekey = trim((string)$module->idnumber) !== ''
                    ? (string)$module->idnumber
                    : 'cm-id-' . $module->id;
                return [
                    'module',
                    'module:' . $coursekey . ':' . $module->modname . ':' . $modulekey,
                    $module->modname . ' / ' . $modulekey,
                ];
            }
            return ['module', 'module-id:' . $context->instanceid, 'Actividad no encontrada'];
        case CONTEXT_USER:
            return ['user', 'user-id:' . $context->instanceid, 'Contexto de usuario'];
        default:
            return [
                'other',
                'context-' . $context->contextlevel . ':' . $context->instanceid,
                'Contexto nivel ' . $context->contextlevel,
            ];
    }
}

global $DB, $CFG;

$admins = get_admins();
$adminids = array_map('intval', array_keys($admins));

$params = ['guestid' => guest_user()->id];
$where = 'u.deleted = 0 AND u.id <> :guestid';
if ($scope === 'lab') {
    $markerclause = $DB->sql_like('u.description', ':marker', false);
    $params['marker'] = '%[LAB-MIGRATION]%';
    if ($adminids) {
        [$adminsql, $adminparams] = $DB->get_in_or_equal($adminids, SQL_PARAMS_NAMED, 'siteadmin');
        $where .= ' AND (' . $markerclause . ' OR u.id ' . $adminsql . ')';
        $params += $adminparams;
    } else {
        $where .= ' AND ' . $markerclause;
    }
}

$userrecords = $DB->get_records_sql(
    "SELECT u.* FROM {user} u WHERE {$where} ORDER BY u.id ASC",
    $params
);
$userids = array_map('intval', array_keys($userrecords));
if (!$userids) {
    cli_error('No se encontraron usuarios para scope=' . $scope . '.');
}

$profilevalues = [];
$profilefields = $DB->get_records_list(
    'user_info_field',
    'shortname',
    ['google_issuer', 'google_sub', 'program_codes'],
    '',
    'id,shortname'
);
$fieldnames = [];
foreach ($profilefields as $field) {
    $fieldnames[(int)$field->id] = (string)$field->shortname;
}
if ($fieldnames) {
    $profiledata = lab3_by_chunks($userids, static function(array $chunk) use ($DB): array {
        [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'profileuser');
        return array_values($DB->get_records_select(
            'user_info_data',
            'userid ' . $insql,
            $inparams,
            'id ASC'
        ));
    });
    foreach ($profiledata as $data) {
        if (!isset($fieldnames[(int)$data->fieldid])) {
            continue;
        }
        $profilevalues[(int)$data->userid][$fieldnames[(int)$data->fieldid]] = trim((string)$data->data);
    }
}

$oauthlinks = [];
$dbman = $DB->get_manager();
if ($dbman->table_exists(new xmldb_table('auth_oauth2_linked_login'))) {
    $linkedrecords = lab3_by_chunks($userids, static function(array $chunk) use ($DB): array {
        [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'oauthuser');
        $sql = "SELECT l.id, l.userid, l.issuerid, l.username, l.email,
                       l.confirmtoken, i.name AS issuername, i.baseurl
                  FROM {auth_oauth2_linked_login} l
                  JOIN {oauth2_issuer} i ON i.id = l.issuerid
                 WHERE l.userid {$insql}
              ORDER BY l.id ASC";
        return array_values($DB->get_records_sql($sql, $inparams));
    });
    foreach ($linkedrecords as $link) {
        $oauthlinks[(int)$link->userid][] = [
            'issuer_id' => (int)$link->issuerid,
            'issuer_name' => (string)$link->issuername,
            'issuer_baseurl' => lab3_normalize_issuer((string)$link->baseurl),
            'external_username' => (string)$link->username,
            'external_email' => core_text::strtolower(trim((string)$link->email)),
            'confirmed' => trim((string)$link->confirmtoken) === '',
        ];
    }
}

$users = [];
foreach ($userrecords as $user) {
    $values = $profilevalues[(int)$user->id] ?? [];
    $issuer = lab3_normalize_issuer((string)($values['google_issuer'] ?? ''));
    $sub = trim((string)($values['google_sub'] ?? ''));
    $identitysource = ($issuer !== '' && $sub !== '') ? 'profile_fields' : 'missing';
    $subcandidate = '';

    if ($sub === '') {
        foreach ($oauthlinks[(int)$user->id] ?? [] as $link) {
            $isgoogle = str_contains($link['issuer_baseurl'], 'accounts.google.com') ||
                str_contains(core_text::strtolower($link['issuer_name']), 'google');
            if (!$link['confirmed'] || !$isgoogle) {
                continue;
            }
            $subcandidate = trim((string)$link['external_username']);
            if ($issuer === '') {
                $issuer = $link['issuer_baseurl'];
            }
            if ($trustoauth && $subcandidate !== '') {
                $sub = $subcandidate;
                $identitysource = 'oauth_linked_login_trusted_by_operator';
            } else {
                $identitysource = 'oauth_linked_login_requires_validation';
            }
            break;
        }
    }

    $users[] = [
        'source' => $source,
        'source_user_id' => (int)$user->id,
        'username' => (string)$user->username,
        'firstname' => (string)$user->firstname,
        'lastname' => (string)$user->lastname,
        'email' => core_text::strtolower(trim((string)$user->email)),
        'auth' => (string)$user->auth,
        'idnumber' => trim((string)$user->idnumber),
        'suspended' => (bool)$user->suspended,
        'timecreated' => (int)$user->timecreated,
        'timemodified' => (int)$user->timemodified,
        'google_issuer' => $issuer,
        'google_sub' => $sub,
        'google_sub_candidate' => $subcandidate,
        'program_codes' => trim((string)($values['program_codes'] ?? '')),
        'identity_source' => $identitysource,
        'is_site_admin' => in_array((int)$user->id, $adminids, true),
        'oauth_links' => $oauthlinks[(int)$user->id] ?? [],
    ];
}

$courses = $DB->get_records('course', null, 'id ASC', 'id,idnumber,shortname,fullname,category');
$categories = $DB->get_records('course_categories', null, 'id ASC', 'id,name,idnumber,path');
$modules = $DB->get_records_sql(
    "SELECT cm.id, cm.course, cm.idnumber, m.name AS modname
       FROM {course_modules} cm
       JOIN {modules} m ON m.id = cm.module
   ORDER BY cm.id ASC"
);

$roles = lab3_by_chunks($userids, static function(array $chunk) use ($DB): array {
    [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'roleuser');
    $sql = "SELECT ra.id, ra.userid, ra.roleid, ra.contextid, ra.component, ra.itemid,
                   r.shortname AS roleshortname, r.name AS rolename, r.archetype,
                   ctx.contextlevel, ctx.instanceid
              FROM {role_assignments} ra
              JOIN {role} r ON r.id = ra.roleid
              JOIN {context} ctx ON ctx.id = ra.contextid
             WHERE ra.userid {$insql}
          ORDER BY ra.id ASC";
    return array_values($DB->get_records_sql($sql, $inparams));
});

$classificationcapabilities = [
    'moodle/site:config',
    'moodle/user:create',
    'moodle/user:update',
    'moodle/role:assign',
    'moodle/role:manage',
    'moodle/course:create',
    'moodle/course:update',
    'moodle/course:manageactivities',
    'moodle/grade:edit',
    'mod/assign:grade',
    'mod/assign:submit',
    'mod/forum:replypost',
    'moodle/course:view',
];
$assignedroleids = array_values(array_unique(array_map(
    static fn(stdClass $role): int => (int)$role->roleid,
    $roles
)));
$roledefinitions = $assignedroleids
    ? $DB->get_records_list('role', 'id', $assignedroleids, 'id ASC', 'id,shortname,name,archetype')
    : [];
$rolesignals = [];
if ($assignedroleids) {
    $rolecapabilities = lab3_by_chunks($assignedroleids, static function(array $chunk) use ($DB): array {
        [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'caprole');
        return array_values($DB->get_records_select(
            'role_capabilities',
            'roleid ' . $insql,
            $inparams,
            'id ASC',
            'id,roleid,capability,permission,contextid'
        ));
    });
    foreach ($rolecapabilities as $capability) {
        $name = (string)$capability->capability;
        if (!in_array($name, $classificationcapabilities, true)) {
            continue;
        }
        if ((int)$capability->permission === CAP_ALLOW) {
            $rolesignals[(int)$capability->roleid][$name] = true;
        }
    }
}

$rolecatalog = [];
foreach ($roledefinitions as $definition) {
    $signals = array_keys($rolesignals[(int)$definition->id] ?? []);
    sort($signals, SORT_STRING);
    $rolecatalog[] = [
        'source' => $source,
        'source_role_id' => (int)$definition->id,
        'role_shortname' => (string)$definition->shortname,
        'role_name' => (string)$definition->name,
        'archetype' => (string)$definition->archetype,
        'allowed_classification_capabilities' => $signals,
    ];
}
if (array_intersect($adminids, $userids)) {
    $rolecatalog[] = [
        'source' => $source,
        'source_role_id' => 0,
        'role_shortname' => 'siteadmin',
        'role_name' => 'Administrador del sitio',
        'archetype' => 'siteadmin',
        'allowed_classification_capabilities' => ['moodle/site:config'],
    ];
}

$roleoutput = [];
foreach ($roles as $role) {
    [$level, $key, $name] = lab3_describe_context($role, $courses, $categories, $modules);
    $roleoutput[] = [
        'source' => $source,
        'source_user_id' => (int)$role->userid,
        'source_role_assignment_id' => (int)$role->id,
        'source_role_id' => (int)$role->roleid,
        'source_context_id' => (int)$role->contextid,
        'context_level' => $level,
        'context_key' => $key,
        'context_name' => $name,
        'role_shortname' => (string)$role->roleshortname,
        'role_name' => (string)$role->rolename,
        'role_archetype' => (string)$role->archetype,
        'component' => (string)$role->component,
        'item_id' => (int)$role->itemid,
    ];
}

// En Moodle el administrador del sitio no es una asignacion de rol ordinaria.
// Se agrega como fila sintetica para que el plan de normalizacion lo trate de
// forma explicita y siempre requiera aprobacion manual.
$systemcontextid = (int)context_system::instance()->id;
foreach (array_intersect($adminids, $userids) as $adminid) {
    $roleoutput[] = [
        'source' => $source,
        'source_user_id' => (int)$adminid,
        'source_role_assignment_id' => 0,
        'source_role_id' => 0,
        'source_context_id' => $systemcontextid,
        'context_level' => 'system',
        'context_key' => 'system',
        'context_name' => 'Sistema',
        'role_shortname' => 'siteadmin',
        'role_name' => 'Administrador del sitio',
        'role_archetype' => 'siteadmin',
        'component' => 'core_siteadmin',
        'item_id' => 0,
    ];
}

$enrolments = lab3_by_chunks($userids, static function(array $chunk) use ($DB): array {
    [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'enroluser');
    $sql = "SELECT ue.id, ue.userid, ue.status, ue.timestart, ue.timeend,
                   e.enrol, e.courseid, c.idnumber AS courseidnumber,
                   c.shortname AS courseshortname, c.fullname AS coursefullname
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid
              JOIN {course} c ON c.id = e.courseid
             WHERE ue.userid {$insql}
          ORDER BY ue.id ASC";
    return array_values($DB->get_records_sql($sql, $inparams));
});
$enroloutput = [];
foreach ($enrolments as $enrolment) {
    $coursekey = trim((string)$enrolment->courseidnumber) !== ''
        ? 'course:' . $enrolment->courseidnumber
        : 'course-id:' . $enrolment->courseid;
    $enroloutput[] = [
        'source' => $source,
        'source_user_id' => (int)$enrolment->userid,
        'source_enrolment_id' => (int)$enrolment->id,
        'course_id' => (int)$enrolment->courseid,
        'course_key' => $coursekey,
        'course_shortname' => (string)$enrolment->courseshortname,
        'course_fullname' => (string)$enrolment->coursefullname,
        'enrol_method' => (string)$enrolment->enrol,
        'status' => (int)$enrolment->status,
        'time_start' => (int)$enrolment->timestart,
        'time_end' => (int)$enrolment->timeend,
    ];
}

$payload = [
    'metadata' => [
        'schema_version' => '1.1',
        'source' => $source,
        'scope' => $scope,
        'site_shortname' => (string)$SITE->shortname,
        'moodle_release' => (string)$CFG->release,
        'generated_at_utc' => gmdate('c'),
        'trust_oauth_username_as_sub' => $trustoauth,
        'admin_accounts_excluded' => false,
        'admin_accounts_included' => true,
    ],
    'users' => $users,
    'roles' => $roleoutput,
    'role_catalog' => $rolecatalog,
    'enrolments' => $enroloutput,
];

$directory = dirname($output);
if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
    cli_error('No fue posible crear el directorio ' . $directory . '.');
}
$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($output, $json . PHP_EOL) === false) {
    cli_error('No fue posible escribir ' . $output . '.');
}

cli_writeln(
    'EXTRACCION_OK source=' . $source .
    ' users=' . count($users) .
    ' roles=' . count($roleoutput) .
    ' enrolments=' . count($enroloutput)
);
