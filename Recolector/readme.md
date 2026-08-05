\# Source Collector



The Source Collector extracts academic information from a Moodle instance and generates a portable migration package that can later be imported by the Consolidator.



The collector performs only read operations against the source Moodle.



No users, courses or activities are modified.



\---



\# What is exported?



The generated package contains:



\- Moodle course backups

\- User inventory

\- Categories

\- Roles

\- Enrollments

\- Plugin inventory

\- Identity information

\- Integrity manifests

\- SHA-256 checksums



The output is a single ZIP file.



\---



\# Requirements



\- Linux

\- PHP CLI

\- Access to the Moodle installation

\- Absolute path to Moodle's `config.php`



\---



\# Installation



Extract the collector anywhere on the server.



Example:



```

/opt/recolector/

```



The collector \*\*does not need to be placed inside the Moodle installation\*\*.



\---



\# Usage



```

./EXPORTAR-ORIGEN.sh <source\_id> "<instance\_name>" <config.php> <output.zip>

```



\---



\# Parameters



\## source\_id



Technical identifier of the Moodle source.



Allowed values:



```

virtual

maestrias

presencial

```



\---



\## instance\_name



Human-readable name that will be stored inside the package manifest.



Example:



```

"Pregrado Virtual"

```



\---



\## config.php



Absolute path to Moodle's configuration file.



Example:



```

/var/www/html/moodle/config.php

```



\---



\## output.zip



Destination path of the generated package.



Example:



```

virtual.zip

```



or



```

/home/admin/exports/virtual.zip

```



\---



\# Example



```

./EXPORTAR-ORIGEN.sh \\

virtual \\

"Pregrado Virtual" \\

/var/www/html/moodle/config.php \\

virtual.zip

```



\---



\# Execution process



The collector automatically performs the following tasks:



1\. Validates PHP CLI.

2\. Loads Moodle configuration.

3\. Verifies the source installation.

4\. Inventories users, courses and plugins.

5\. Generates Moodle backups.

6\. Builds migration metadata.

7\. Calculates SHA-256 hashes.

8\. Generates the package manifest.

9\. Compresses the final package.



\---



\# Output



A successful execution generates a ZIP package containing:



```

courses/

inventory/

identities/

manifest.json

checksums.sha256

...

```



This package is the input required by the Consolidator.



\---



\# Safety



The collector operates in \*\*read-only mode\*\*.



It never:



\- modifies Moodle configuration;

\- changes users;

\- edits courses;

\- alters enrollments.



The source instance remains unchanged.



\---



\# Docker



If Moodle runs inside Docker, the collector must be executed in an environment that has access to:



\- PHP CLI

\- the Moodle installation

\- the target `config.php`



Typically, this means executing the collector inside the Moodle container or another environment with equivalent access.



\---



\# License



MIT License.

