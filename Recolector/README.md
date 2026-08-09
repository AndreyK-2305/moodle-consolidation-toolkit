# Recolector Moodle

Versión `7.2.1-linux-rc2`.

El Recolector se ejecuta en cada Moodle de origen y genera un paquete ZIP
sellado, portable y compatible con el Consolidador `7.2.1-linux-rc6`.

La herramienta consulta la instalación de Moodle, crea un backup oficial `.mbz`
por curso y exporta inventarios, identidades, roles, matrículas, plugins y
checkpoints. No modifica usuarios, cursos, matrículas ni configuración del
sitio. La API oficial de backup de Moodle sí puede utilizar archivos temporales
durante el proceso.

> Ejecute esta versión primero en un entorno de ensayo y coordine una ventana
> de mantenimiento o de cambios congelados antes de recolectar una instancia
> institucional.

## Compatibilidad dentro del proyecto

Esta distribución fue preparada para los Moodle 4.5 de origen del proyecto de
consolidación UFPS y produce:

- Contrato de paquete `moodle-consolidation-source` versión `1.0`.
- Contrato de identidades versión `1.2`.
- `identity_scope=all`, requerido por el Consolidador en modo producción.
- ZIP sellado y hash SHA-256 externo.

No es un backup completo del sitio y no reemplaza la política institucional de
respaldos. Es un paquete especializado de migración.

## Requisitos

- Linux con Bash.
- PHP CLI compatible con la instalación Moodle.
- Extensión PHP `zip`/`ZipArchive`.
- Acceso de lectura al `config.php` y a los datos utilizados por Moodle.
- Permiso de escritura en el directorio de salida.
- Espacio suficiente para los `.mbz`, inventarios, ZIP final y temporales.
- `systemd-run`, `systemctl` y `sudo` únicamente para `--background`.
- PHPMailer incluido en Moodle si se habilitan correos SMTP.

Comprobación rápida:

```bash
php -v
php -r 'echo class_exists("ZipArchive") ? "ZIP_OK\n" : "FALTA_ZIP\n";'
test -r /var/www/html/config.php && printf 'CONFIG_LEGIBLE\n'
```

En Ubuntu, la extensión suele pertenecer al paquete `php-zip` correspondiente
a la versión de PHP instalada. La instalación de paquetes del sistema debe
seguir el procedimiento de administración del servidor.

## Archivos principales

```text
EXPORTAR-ORIGEN.sh          Exportación normal de una instancia
VALIDAR-PAQUETE.sh          Auditoría exhaustiva opcional
smtp-config.example.json    Plantilla de notificaciones
VERSION.txt                 Versión de la distribución
scripts/                    Motor PHP
salidas/                    Resultados predeterminados; se crea al ejecutar
```

No invoque directamente los archivos de `scripts/` salvo durante diagnóstico
dirigido. Los puntos de entrada soportados son los dos archivos `.sh`.

## Preparación

Desde la carpeta del Recolector:

```bash
chmod +x EXPORTAR-ORIGEN.sh VALIDAR-PAQUETE.sh
cat VERSION.txt
```

Debe mostrar:

```text
7.2.1-linux-rc2
```

Ayuda integrada:

```bash
./EXPORTAR-ORIGEN.sh --help
./VALIDAR-PAQUETE.sh --help
```

## Identificador de la instancia

El primer argumento de la exportación es el nombre del ZIP. El nombre sin
`.zip` se usa también como:

- `source_id` del paquete.
- Nombre descriptivo interno del origen.
- Espacio de checkpoints.
- Prefijo de logs y estados.
- Nombre de la unidad `systemd` en segundo plano.

Reglas:

- Debe iniciar con una letra minúscula.
- Puede contener `a-z`, `0-9`, guion y guion bajo.
- Puede tener hasta 63 caracteres.
- La única extensión aceptada es `.zip` en minúsculas.
- Cada instancia debe utilizar un identificador diferente.

Ejemplos válidos:

```text
virtual.zip
maestrias.zip
presencial.zip
campus-norte_2026.zip
```

No incluya rutas en este argumento. El directorio de salida se configura con la
variable `MOODLE_COLLECTOR_OUTPUT_DIR`.

## Exportación en primer plano

### Ruta predeterminada de Moodle

Si `config.php` está en `/var/www/html/config.php`:

```bash
./EXPORTAR-ORIGEN.sh virtual.zip
```

### Ruta personalizada

```bash
./EXPORTAR-ORIGEN.sh maestrias.zip /srv/moodle/config.php
```

### Directorio de salida personalizado

```bash
MOODLE_COLLECTOR_OUTPUT_DIR=/mnt/exportaciones-moodle \
  ./EXPORTAR-ORIGEN.sh presencial.zip /srv/moodle/config.php
```

La ejecución muestra cada curso de forma secuencial. Un cierre correcto termina
con una línea similar a:

```text
RECOLECTOR_OK source=virtual output=/ruta/salidas/virtual.zip
```

## Salidas de la exportación

Con la configuración predeterminada:

```text
salidas/virtual.zip
salidas/virtual.zip.sha256
salidas/virtual.status.json
salidas/logs/virtual-AAAAmmddTHHMMSSZ-PID.log
salidas/.moodle-collector-work-virtual/
```

| Archivo | Uso |
|---|---|
| `virtual.zip` | Paquete que recibirá el Consolidador. |
| `virtual.zip.sha256` | Verificación del ZIP durante almacenamiento o transporte. |
| `virtual.status.json` | Estado, etapa, código de salida, duración y rutas. |
| `logs/...` | Evidencia operativa detallada. |
| `.moodle-collector-work-virtual/` | Artefactos y checkpoints reanudables. |

Compruebe siempre el hash antes de transferir el paquete:

```bash
cd salidas
sha256sum -c virtual.zip.sha256
```

El resultado esperado es:

```text
virtual.zip: OK
```

## Ejecución en segundo plano

El modo `--background` crea una unidad transitoria del sistema. Debe invocarse
con `sudo`:

```bash
sudo ./EXPORTAR-ORIGEN.sh --background virtual.zip
```

Con ruta explícita:

```bash
sudo ./EXPORTAR-ORIGEN.sh --background \
  virtual.zip /srv/moodle/config.php
```

Por defecto, el proceso se ejecuta como el usuario que invocó `sudo`. Para usar
el usuario del servidor web:

```bash
sudo env MOODLE_COLLECTOR_RUN_AS_USER=www-data \
  ./EXPORTAR-ORIGEN.sh --background \
  virtual.zip /srv/moodle/config.php
```

El usuario seleccionado debe poder:

- Leer `config.php` y los datos requeridos por Moodle.
- Escribir en el directorio de salida.
- Leer y ejecutar la carpeta del Recolector.

Seguimiento:

```bash
sudo systemctl status moodle-recolector-virtual.service
tail -F salidas/logs/virtual-*.log
cat salidas/virtual.status.json
```

Detención controlada, si fuera necesaria:

```bash
sudo systemctl stop moodle-recolector-virtual.service
```

Detener una ejecución no borra los checkpoints. Al iniciar nuevamente con el
mismo identificador, cada curso aprobado se reutiliza si su estado y sus
artefactos todavía coinciden.

## Reanudación y checkpoints

El Recolector procesa un curso a la vez. Para cada curso conserva:

- Inventario detallado.
- Backup `.mbz`.
- SHA-256, tamaño y fecha de modificación.
- Hash del estado lógico del curso.
- Checkpoint con estado `prepared`.

Si una ejecución se interrumpe, repita el mismo comando. Los cursos con
checkpoint válido no se vuelven a respaldar.

Si un curso cambió después de crear el checkpoint, la herramienta se detiene en
vez de mezclar estados diferentes. En ese caso debe coordinarse una nueva
captura limpia; no altere manualmente los checkpoints ni sustituya `.mbz`
dentro del directorio de trabajo.

## Auditoría exhaustiva opcional

La exportación normal sella el paquete sin releer por completo cada `.mbz`. La
auditoría exhaustiva realiza deliberadamente esas comprobaciones costosas.

Ejecutar junto al paquete:

```bash
./VALIDAR-PAQUETE.sh salidas/virtual.zip
```

El segundo argumento es opcional. Solo permite localizar PHPMailer cuando se
enviará una notificación SMTP y Moodle no usa la ruta predeterminada:

```bash
./VALIDAR-PAQUETE.sh \
  salidas/virtual.zip /srv/moodle/config.php
```

En segundo plano:

```bash
sudo ./VALIDAR-PAQUETE.sh --background \
  /ruta/virtual.zip /srv/moodle/config.php
```

Seguimiento:

```bash
sudo systemctl status \
  moodle-recolector-validacion-virtual.service
tail -F /ruta/logs/virtual-validacion-*.log
cat /ruta/virtual.validacion.status.json
```

Un resultado correcto termina con:

```text
VALIDACION_OK archivos=... cursos=... advertencias=... reporte=...
```

La auditoría genera junto al ZIP:

```text
virtual.validacion.json
virtual.validacion.status.json
logs/virtual-validacion-AAAAmmddTHHMMSSZ-PID.log
```

La auditoría:

- Recalcula el SHA-256 del ZIP.
- Compara el `.zip.sha256` si existe.
- Abre el ZIP con comprobación de consistencia.
- Verifica rutas inseguras, duplicadas, faltantes o inesperadas.
- Recalcula el hash de cada artefacto listado internamente.
- Cruza manifiesto, inventarios, checkpoints y respaldos.
- Comprueba que los `.mbz` no hayan sido recomprimidos dentro del ZIP exterior.

Código de salida `0` significa validación correcta. Código `1` indica una o más
inconsistencias. Si falta el `.zip.sha256`, la auditoría interna continúa y lo
registra como advertencia.

## Notificaciones SMTP opcionales

La exportación y la auditoría buscan automáticamente `smtp-config.json` en la
carpeta del Recolector.

Crear la configuración:

```bash
cp smtp-config.example.json smtp-config.json
chmod 600 smtp-config.json
```

Edite únicamente `smtp-config.json`:

```json
{
  "enabled": true,
  "host": "smtp.example.org",
  "port": 587,
  "encryption": "tls",
  "auth": true,
  "username": "usuario-smtp",
  "password": "CAMBIAR",
  "from_email": "moodle@example.org",
  "from_name": "Recolector Moodle",
  "to": [
    "administrador@example.org"
  ],
  "timeout_seconds": 10
}
```

Condiciones:

- `enabled` debe ser el booleano `true`, sin comillas.
- `encryption` admite `tls`, `ssl` o `none`.
- `timeout_seconds` debe estar entre 3 y 30.
- Si `auth` es `true`, usuario y contraseña son obligatorios.
- Remitente y destinatarios deben ser aceptados por el proveedor SMTP.
- El `config.php` indicado debe permitir cargar PHPMailer desde Moodle.

Para guardar la configuración fuera de la herramienta:

```bash
MOODLE_COLLECTOR_SMTP_CONFIG=/etc/moodle-recolector/smtp.json \
  ./EXPORTAR-ORIGEN.sh virtual.zip /srv/moodle/config.php
```

Los correos informan éxito o error. Un problema SMTP se registra como
`SMTP_WARNING`, pero no cambia el resultado real de la exportación o auditoría.
El log y el archivo `*.status.json` son la evidencia oficial.

No suba `smtp-config.json` al repositorio.

## Ejecución cuando Moodle usa Docker

El Recolector debe ejecutarse dentro del contenedor Moodle, o en otro entorno
que tenga simultáneamente:

- PHP CLI compatible.
- Acceso a la instalación de Moodle.
- Lectura de `config.php` y `moodledata`.
- Acceso a la base configurada por Moodle.
- Un directorio persistente para las salidas.

Ejemplo genérico si la carpeta del Recolector y `/exports` ya están montadas en
el contenedor; ajuste el servicio y las rutas a su despliegue:

```bash
docker compose exec -T -u www-data moodle \
  bash -lc '
    cd /opt/moodle-recolector
    MOODLE_COLLECTOR_OUTPUT_DIR=/exports \
      ./EXPORTAR-ORIGEN.sh virtual.zip /var/www/html/config.php
  '
```

Auditoría dentro del mismo contenedor:

```bash
docker compose exec -T -u www-data moodle \
  bash -lc '
    cd /opt/moodle-recolector
    ./VALIDAR-PAQUETE.sh \
      /exports/virtual.zip /var/www/html/config.php
  '
```

No use `--background` dentro de un contenedor que no ejecute `systemd`. En ese
caso, administre la continuidad desde el host, el orquestador o una sesión
supervisada.

## Contenido del paquete

```text
cursos/*.mbz
inventarios/*.json
identidades.json
inventario-origen.json
plugins.json
checkpoints/*.json
manifest.json
checksums.sha256
```

Los `.mbz` se almacenan sin recomprimir dentro del ZIP exterior. Esta decisión
reduce trabajo innecesario y permite verificar tamaños y hashes de manera
estable.

## Contrato de identidad OAuth

`identidades.json` versión `1.2` distingue:

| Campo | Significado |
|---|---|
| `google_sub` | Subject de Google únicamente cuando existe evidencia verificable. |
| `google_sub_verified` | Indica si `issuer + sub` tiene evidencia suficiente. |
| `oauth_linked_username` | Identificador que Moodle conserva en el vínculo OAuth. |
| `oauth_identifier_kind` | Clasificación `sub`, `email`, `opaque` o `unknown`. |
| `oauth_identifier_evidence` | Evidencia utilizada para la clasificación. |
| `oauth_links` | Todos los vínculos del usuario. |
| `oauth_issuers` | Emisores y mapeos de campos encontrados. |

Un correo usado para iniciar sesión se conserva como identificador OAuth de tipo
`email`; nunca se convierte artificialmente en `google_sub`. Si hay varios
vínculos sin selección inequívoca, el paquete conserva la ambigüedad para que el
Consolidador solicite revisión.

## Seguridad de la información

El ZIP, los `.mbz`, inventarios, estados y logs pueden contener datos personales
y académicos.

- Use `umask 077` o permisos equivalentes en el almacenamiento de destino.
- Transfiera los paquetes por un canal institucional seguro.
- Limite lectura y escritura al equipo autorizado.
- No adjunte los paquetes completos en incidencias ordinarias.
- No incluya credenciales, contenido académico ni datos personales en SMTP.
- No suba `salidas/`, directorios de trabajo ni `smtp-config.json` a Git.
- Conserve el ZIP y su hash como una pareja inseparable durante el transporte.

## Solución de problemas

### No se encontró `config.php`

Indique la ruta real como segundo argumento:

```bash
./EXPORTAR-ORIGEN.sh virtual.zip /ruta/real/config.php
```

### PHP no tiene `ZipArchive`

Compruebe qué PHP utiliza la terminal y la extensión cargada:

```bash
command -v php
php --ini
php -m | grep -i '^zip$'
```

### El usuario de segundo plano no puede escribir

Compruebe el directorio de salida con el mismo usuario que ejecutará la unidad:

```bash
sudo -u www-data test -w /mnt/exportaciones-moodle \
  && printf 'SALIDA_ESCRIBIBLE\n'
```

### Existe una unidad activa con el mismo identificador

Revise su estado antes de iniciar otra:

```bash
sudo systemctl status moodle-recolector-virtual.service
```

### Falla la auditoría

No edite el ZIP para corregirlo. Revise:

```text
virtual.validacion.json
virtual.validacion.status.json
logs/virtual-validacion-*.log
```

Si existe una inconsistencia real, genere un paquete nuevo desde el origen.

## Referencia rápida

```bash
# Exportar con config.php predeterminado
./EXPORTAR-ORIGEN.sh virtual.zip

# Exportar con config.php explícito
./EXPORTAR-ORIGEN.sh virtual.zip /srv/moodle/config.php

# Exportar en segundo plano
sudo ./EXPORTAR-ORIGEN.sh --background \
  virtual.zip /srv/moodle/config.php

# Auditar el paquete
./VALIDAR-PAQUETE.sh salidas/virtual.zip

# Auditar en segundo plano
sudo ./VALIDAR-PAQUETE.sh --background \
  /ruta/virtual.zip /srv/moodle/config.php

# Verificar transporte
cd salidas
sha256sum -c virtual.zip.sha256
```

## Resultado esperado para entrega al Consolidador

Antes de entregar un origen, confirme:

- `RECOLECTOR_OK` en el log.
- `virtual.status.json` con `state: success`.
- `sha256sum -c` con resultado `OK`.
- `VALIDACION_OK` si se ejecutó la auditoría exhaustiva.
- ZIP custodiado y transferido sin modificar.
- Identificador único frente a los demás orígenes.
