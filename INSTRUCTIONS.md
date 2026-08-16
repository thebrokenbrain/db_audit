# Instrucciones

Drupal 10.3+ / 11 · MySQL o MariaDB.

## Instalar

**1.** Copiar el módulo:

```bash
cp -r db_audit web/modules/custom/db_audit
drush en db_audit -y
```

**2.** Añadir al final de `settings.php`:

```php
$db_audit_path = 'modules/custom/db_audit';
if (getenv('DB_AUDIT_DRIVER') === '1'
  && is_dir($app_root . '/' . $db_audit_path . '/src/Driver/Database/auditmysql')) {

  $databases['default']['default']['driver'] = 'auditmysql';
  $databases['default']['default']['namespace'] = 'Drupal\\db_audit\\Driver\\Database\\auditmysql';
  $databases['default']['default']['autoload'] = $db_audit_path . '/src/Driver/Database/auditmysql/';
  $databases['default']['default']['dependencies'] = [
    'mysql' => ['namespace' => 'Drupal\\mysql', 'autoload' => 'core/modules/mysql/src/'],
    'db_audit' => ['namespace' => 'Drupal\\db_audit', 'autoload' => $db_audit_path . '/src/'],
  ];

  $settings['db_audit'] = [
    'enabled' => getenv('DB_AUDIT_ENABLED') !== '0',
    'sample_rate' => (int) (getenv('DB_AUDIT_SAMPLE_RATE') ?: 1),
    'backtrace_limit' => (int) (getenv('DB_AUDIT_BACKTRACE') ?: 30),
    'max_queries' => 5000,
    'capture_args' => FALSE,
  ];
}
```

Ajusta `$db_audit_path` si el módulo no está en `modules/custom/`. Es relativo al directorio que contiene `core/`.

**3.** Activar con variables de entorno:

```
DB_AUDIT_DRIVER=1
DB_AUDIT_ENABLED=1
```

**4.** Comprobar:

```bash
drush db-audit:diagnose      # debe decir: auditmysql (instrumentado)
```

## Auditar

```bash
drush db-audit:rtt --samples=100    # 1. medir latencia (obligatorio)
drush db-audit:tag base             # 2. etiquetar la tanda
                                    # 3. lanzar tráfico contra el sitio
drush db-audit:summary              # 4. leer
drush db-audit:routes
drush db-audit:findings
```

También en la interfaz: **Informes › Auditoría de base de datos**.

Para comparar antes/después: `drush db-audit:tag otro-nombre`, repetir el mismo tráfico, y `drush db-audit:summary --tag=base` frente a `--tag=otro-nombre`.

## Interpretar

Mira el **% de red** del resumen:

| % red | Significa | Qué hacer |
|---|---|---|
| > 60% | El tiempo se va en idas y vueltas | Reducir el **número** de consultas: `loadMultiple()`, `IN()`, caché estática |
| < 40% | Hay ejecución pesada en el servidor | `EXPLAIN` e índices |

Luego `db-audit:findings`, ordenado por impacto. Cada hallazgo lleva fichero y línea.

| Hallazgo | Qué hacer |
|---|---|
| `network_bound` | Diagnóstico: ataca volumen, no SQL |
| `emulate_prepares_off` | Revisar array `pdo` en settings.php — duplica el coste de red |
| `n_plus_one` | Agrupar en una consulta |
| `duplicate_query` | Caché estática en memoria |
| `cache_in_database` | Un bin de caché no va a Redis |
| `connection_overhead` | Conexiones persistentes o pooler |
| `slow_query` | `EXPLAIN` e índices |
| `hot_infrastructure_table` | Mover ese servicio fuera de la BD |

## Comandos

| Comando | Para qué |
|---|---|
| `db-audit:rtt` | Mide latencia y coste de conexión |
| `db-audit:diagnose` | Estado de la conexión |
| `db-audit:tag <nombre>` | Etiqueta la tanda actual |
| `db-audit:summary` | Resumen global |
| `db-audit:routes` | Rutas por consultas/request |
| `db-audit:findings` | Hallazgos por impacto |
| `db-audit:queries` | Consultas agrupadas (`--module=`, `--route=`) |
| `db-audit:modules` | Reparto por módulo |
| `db-audit:install-schema` | Crea las tablas (sólo si usas base separada) |
| `db-audit:purge` | Borra lo capturado (`--tag=`, `--days=`) |

Todos aceptan `--tag=` y `--format=json`.

## Desactivar

| Qué | Cómo |
|---|---|
| Parar la captura | `DB_AUDIT_ENABLED=0` |
| Quitar el driver | Quitar `DB_AUDIT_DRIVER` |
| Reducir impacto | `DB_AUDIT_SAMPLE_RATE=100` |
| Desinstalar | `drush pmu db_audit -y` **antes** de borrar el código |

## Notas

- Sólo en preproducción. Coste: 5-15% sobre el tiempo de base de datos.
- Opcional: declarar una conexión `dbaudit` en `settings.php` para guardar los resultados en otra base y no ensuciar la medición. Sin ella, se guardan en la propia base auditada (3 INSERT por request).
- Al comparar tandas, evita la caché de página: usa una query string distinta por petición (`/node/1?cb=x`) o autentícate.
- No vuelvas a medir el RTT en mitad de una comparación.

Detalle completo en [README.md](README.md).
