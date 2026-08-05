#!/usr/bin/env bash
set -euo pipefail
umask 077

readonly collector_version="7.2.0-linux-rc2"
readonly default_moodle_config="/var/www/html/config.php"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
script_path="$script_dir/$(basename "${BASH_SOURCE[0]}")"

usage() {
  cat <<'EOF'
Uso:
  ./EXPORTAR-ORIGEN.sh [--background] nombre.zip [/ruta/moodle/config.php]

Ejemplos:
  ./EXPORTAR-ORIGEN.sh campus-norte.zip
  ./EXPORTAR-ORIGEN.sh campus-norte.zip /srv/moodle/config.php
  sudo ./EXPORTAR-ORIGEN.sh --background campus-norte.zip

Si se omite config.php se usa /var/www/html/config.php.
El nombre, sin .zip, se usa como identificador interno del origen.
EOF
}

fail_usage() {
  echo "$1" >&2
  usage >&2
  exit 2
}

execution_mode="foreground"
case "${1:-}" in
  --background)
    execution_mode="background-launcher"
    shift
    ;;
  --internal-run)
    execution_mode="background"
    shift
    ;;
  -h|--help)
    usage
    exit 0
    ;;
  --*)
    fail_usage "Opción no reconocida: $1"
    ;;
esac

(( $# >= 1 && $# <= 2 )) || \
  fail_usage "Se requiere el nombre del ZIP y, opcionalmente, config.php."

zip_name="$1"
moodle_config="${2:-$default_moodle_config}"

[[ "$zip_name" != */* && "$zip_name" != "." && "$zip_name" != ".." ]] || \
  fail_usage "Indique solo el nombre del ZIP, sin directorios."
source_id="${zip_name%.zip}"
[[ "$source_id" =~ ^[a-z][a-z0-9_-]{0,62}$ ]] || \
  fail_usage "El nombre debe iniciar en minúscula y usar solo a-z, 0-9, _ o -."
[[ "$zip_name" == "$source_id" || "$zip_name" == "$source_id.zip" ]] || \
  fail_usage "La única extensión permitida es .zip en minúsculas."

source_name="$source_id"
output_dir="${MOODLE_COLLECTOR_OUTPUT_DIR:-$script_dir/salidas}"
output_dir_created=0
if [[ ! -d "$output_dir" ]]; then
  mkdir -p "$output_dir"
  output_dir_created=1
fi
output_dir="$(cd "$output_dir" && pwd -P)"
output_zip="$output_dir/$source_id.zip"
work_dir="${MOODLE_COLLECTOR_WORKDIR:-$output_dir/.moodle-collector-work-$source_id}"
mkdir -p "$work_dir"
work_dir="$(cd "$work_dir" && pwd -P)"
logs_dir="$output_dir/logs"
mkdir -p "$logs_dir"

if [[ -r "$moodle_config" ]]; then
  moodle_config="$(cd "$(dirname "$moodle_config")" && pwd -P)/$(basename "$moodle_config")"
fi

smtp_config="${MOODLE_COLLECTOR_SMTP_CONFIG:-$script_dir/smtp-config.json}"
if [[ "$smtp_config" != /* ]]; then
  smtp_config="$script_dir/$smtp_config"
fi

run_token="$(date -u +%Y%m%dT%H%M%SZ)-$$"
log_file="${MOODLE_COLLECTOR_LOG_FILE:-$logs_dir/$source_id-$run_token.log}"
status_file="$output_dir/$source_id.status.json"
unit_name="moodle-recolector-$source_id"
started_epoch="$(date +%s)"
current_stage="preflight"

json_escape() {
  local value="$1"
  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  value="${value//$'\n'/\\n}"
  value="${value//$'\r'/\\r}"
  value="${value//$'\t'/\\t}"
  printf '%s' "$value"
}

write_status() {
  local state="$1"
  local exit_code="$2"
  local stage="$3"
  local ended_at="$4"
  local now duration temporary
  now="$(date +%s)"
  duration=$(( now - started_epoch ))
  temporary="$status_file.tmp.$$"
  printf '{\n' > "$temporary"
  printf '  "schema_version": "1.0",\n' >> "$temporary"
  printf '  "collector_version": "%s",\n' "$(json_escape "$collector_version")" >> "$temporary"
  printf '  "source_id": "%s",\n' "$(json_escape "$source_id")" >> "$temporary"
  printf '  "state": "%s",\n' "$(json_escape "$state")" >> "$temporary"
  printf '  "stage": "%s",\n' "$(json_escape "$stage")" >> "$temporary"
  printf '  "execution_mode": "%s",\n' "$(json_escape "$execution_mode")" >> "$temporary"
  printf '  "exit_code": %d,\n' "$exit_code" >> "$temporary"
  printf '  "duration_seconds": %d,\n' "$duration" >> "$temporary"
  printf '  "output_zip": "%s",\n' "$(json_escape "$output_zip")" >> "$temporary"
  printf '  "log_file": "%s",\n' "$(json_escape "$log_file")" >> "$temporary"
  printf '  "updated_at_utc": "%s",\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "$temporary"
  printf '  "ended_at_utc": "%s"\n' "$(json_escape "$ended_at")" >> "$temporary"
  printf '}\n' >> "$temporary"
  mv -f "$temporary" "$status_file"
}

runtime_preflight() {
  command -v php >/dev/null 2>&1 || {
    echo "No se encontró PHP CLI." >&2
    return 1
  }
  [[ -r "$moodle_config" ]] || {
    echo "No se puede leer config.php: $moodle_config" >&2
    return 1
  }
  php -r 'exit(class_exists("ZipArchive") ? 0 : 1);' || {
    echo "El PHP CLI no tiene disponible la extensión zip/ZipArchive." >&2
    return 1
  }
}

send_notification() {
  local result="$1"
  local exit_code="$2"
  local stage="$3"
  local duration hard_timeout
  [[ -r "$smtp_config" ]] || return 0
  command -v php >/dev/null 2>&1 || return 0
  duration=$(( $(date +%s) - started_epoch ))
  hard_timeout="${MOODLE_COLLECTOR_SMTP_HARD_TIMEOUT:-20}"
  [[ "$hard_timeout" =~ ^[0-9]+$ ]] || hard_timeout=20
  (( hard_timeout >= 5 && hard_timeout <= 60 )) || hard_timeout=20
  if command -v timeout >/dev/null 2>&1; then
    timeout "${hard_timeout}s" php "$script_dir/scripts/notify-smtp.php" \
      "--moodleconfig=$moodle_config" \
      "--smtpconfig=$smtp_config" \
      "--sourceid=$source_id" \
      "--result=$result" \
      "--exitcode=$exit_code" \
      "--stage=$stage" \
      "--duration=$duration" \
      "--outputzip=$output_zip" \
      "--logfile=$log_file" || \
        echo "SMTP_WARNING La notificación excedió el tiempo máximo o no pudo ejecutarse." >&2
  else
    php "$script_dir/scripts/notify-smtp.php" \
      "--moodleconfig=$moodle_config" \
      "--smtpconfig=$smtp_config" \
      "--sourceid=$source_id" \
      "--result=$result" \
      "--exitcode=$exit_code" \
      "--stage=$stage" \
      "--duration=$duration" \
      "--outputzip=$output_zip" \
      "--logfile=$log_file" || true
  fi
}

finish_run() {
  local exit_code=$?
  local result ended_at final_stage
  trap - EXIT
  set +e
  ended_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  if (( exit_code == 0 )); then
    result="success"
    final_stage="completed"
  else
    result="error"
    final_stage="$current_stage"
  fi
  write_status "$result" "$exit_code" "$final_stage" "$ended_at"
  send_notification "$result" "$exit_code" "$final_stage"
  exit "$exit_code"
}

launch_background() {
  local run_user run_group
  (( EUID == 0 )) || {
    echo "El modo --background usa una unidad systemd del sistema." >&2
    echo "Repita el comando con sudo para que continúe al cerrar SSH." >&2
    return 1
  }
  command -v systemd-run >/dev/null 2>&1 || {
    echo "No se encontró systemd-run." >&2
    return 1
  }
  command -v systemctl >/dev/null 2>&1 || {
    echo "No se encontró systemctl." >&2
    return 1
  }
  if systemctl is-active --quiet "$unit_name.service"; then
    echo "Ya existe una recolección activa: $unit_name.service" >&2
    return 1
  fi

  if [[ -n "${MOODLE_COLLECTOR_RUN_AS_USER:-}" ]]; then
    run_user="$MOODLE_COLLECTOR_RUN_AS_USER"
    id "$run_user" >/dev/null 2>&1 || {
      echo "Usuario de ejecución inexistente: $run_user" >&2
      return 1
    }
    run_group="$(id -gn "$run_user")"
  elif [[ -n "${SUDO_USER:-}" && "$SUDO_USER" != "root" ]]; then
    run_user="$SUDO_USER"
    run_group="$(id -gn "$run_user")"
  else
    run_user="root"
    run_group="root"
  fi

  touch "$log_file"
  if [[ "$run_user" != "root" ]]; then
    command -v runuser >/dev/null 2>&1 || {
      echo "No se encontró runuser para validar el usuario de ejecución." >&2
      return 1
    }
    if (( output_dir_created == 1 )); then
      chown "$run_user:$run_group" "$output_dir"
    fi
    chown "$run_user:$run_group" "$work_dir" "$logs_dir" "$log_file"
    if ! runuser -u "$run_user" -- test -w "$output_dir"; then
      echo "El usuario $run_user no puede escribir en $output_dir." >&2
      echo "Ajuste permisos o use MOODLE_COLLECTOR_OUTPUT_DIR." >&2
      return 1
    fi
  fi

  systemd-run \
    --unit="$unit_name" \
    --collect \
    --service-type=exec \
    --uid="$run_user" \
    --gid="$run_group" \
    --working-directory="$script_dir" \
    --setenv="PATH=$PATH" \
    --setenv="MOODLE_COLLECTOR_OUTPUT_DIR=$output_dir" \
    --setenv="MOODLE_COLLECTOR_WORKDIR=$work_dir" \
    --setenv="MOODLE_COLLECTOR_SMTP_CONFIG=$smtp_config" \
    --setenv="MOODLE_COLLECTOR_LOG_FILE=$log_file" \
    --property="StandardOutput=append:$log_file" \
    --property="StandardError=append:$log_file" \
    "$script_path" --internal-run "$source_id.zip" "$moodle_config"

  echo "RECOLECTOR_BACKGROUND_OK source=$source_id unit=$unit_name.service"
  echo "Estado: systemctl status $unit_name.service"
  echo "Log:    tail -f $log_file"
  echo "Resumen: $status_file"
}

if [[ "$execution_mode" == "background-launcher" ]]; then
  runtime_preflight
  launch_background
  exit 0
fi

run_export() {
  set -euo pipefail
  local hash_sidecar hash_line final_sha separator final_name
  local -a hash_lines
  trap finish_run EXIT
  write_status "running" 0 "$current_stage" ""

  runtime_preflight
  current_stage="export"
  write_status "running" 0 "$current_stage" ""

  echo "RECOLECTOR_INICIO version=$collector_version source=$source_id mode=$execution_mode"
  php "$script_dir/scripts/source-export.php" \
    "--config=$moodle_config" \
    "--sourceid=$source_id" \
    "--sourcename=$source_name" \
    "--outputdir=$work_dir" \
    "--outputzip=$output_zip" \
    "--scope=all" \
    "--trustoauthusernameassub=0"

  current_stage="final-hash"
  write_status "running" 0 "$current_stage" ""
  hash_sidecar="$output_zip.sha256"
  [[ -r "$hash_sidecar" ]] || {
    echo "No se generó el SHA-256 externo: $hash_sidecar" >&2
    return 1
  }
  mapfile -t hash_lines < "$hash_sidecar"
  (( ${#hash_lines[@]} == 1 )) || {
    echo "El archivo SHA-256 externo no tiene el formato esperado." >&2
    return 1
  }
  hash_line="${hash_lines[0]}"
  final_sha="${hash_line:0:64}"
  separator="${hash_line:64:2}"
  final_name="${hash_line:66}"
  [[ "$final_sha" =~ ^[a-f0-9]{64}$ &&
        "$separator" == "  " &&
        "$final_name" == "$source_id.zip" ]] || {
    echo "El archivo SHA-256 externo no corresponde al ZIP generado." >&2
    return 1
  }
  printf '%s\n' "$hash_line"
  echo "RECOLECTOR_OK source=$source_id output=$output_zip"
  echo "El ZIP contiene datos institucionales sensibles; restrinja su acceso."
}

if [[ "$execution_mode" == "foreground" ]]; then
  touch "$log_file"
  if command -v tee >/dev/null 2>&1; then
    set +e
    run_export 2>&1 | tee -a "$log_file"
    export_exit="${PIPESTATUS[0]}"
    set -e
    exit "$export_exit"
  fi
fi

run_export
