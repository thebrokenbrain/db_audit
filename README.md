# Database Audit

Auditoría de las consultas que Drupal envía a la base de datos, pensada para
diagnosticar la degradación de rendimiento que aparece al separar la aplicación
y la base de datos en zonas de disponibilidad distintas.

Probado contra Drupal 10.6 / MariaDB 10.11.

## El problema que resuelve

Al mover un sitio a una topología donde la aplicación y la base de datos están
en zonas distintas, el coste dominante deja de ser la complejidad del SQL y
pasa a ser el **número de idas y vueltas**:

| | On-premise | Cross-AZ |
|---|---|---|
| RTT a la BD | ~0,05–0,1 ms | ~0,5–1,5 ms |
| Consulta de 0,05 ms de ejecución | ~0,15 ms | ~1,05 ms |
| Request con 500 consultas | ~75 ms | ~575 ms |

Ninguna de esas consultas es "lenta". Por eso **el slow query log de RDS y
Performance Insights no ven este problema**: del lado del servidor todo se
resuelve en microsegundos. Sólo se ve desde dentro de PHP, contando consultas y
atribuyéndolas a quien las lanza.

Este módulo hace exactamente eso, y traduce cada hallazgo a **milisegundos
recuperables por request**, para poder priorizar por impacto real.

## Cómo captura

Un driver de base de datos propio (`auditmysql`) que extiende el driver `mysql`
de core sin alterar su comportamiento: hereda `Select`, `Insert`, `Schema` y
todo lo demás. Sólo sustituye la clase que envuelve los statements, para
cronometrar cada `execute()`.

Se eligió este punto de enganche, y no `Database::startLog()` ni los eventos de
base de datos de core, porque:

- `Connection::query()` no ve las ejecuciones que hace el código que llama
  directamente a `prepareStatement()->execute()`.
- Los eventos de core (`StatementExecutionStartEvent`) exigen que el contenedor
  de servicios exista — `Connection::dispatchEvent()` lanza excepción si no
  está —, lo que deja fuera **todas las consultas del bootstrap**, que son
  muchas y se pagan en todos los requests.

Envolviendo el statement se captura el 100% del tráfico desde la primera
consulta, sin contenedor, y funciona igual en web, Drush, cron y queue workers.

Durante el request **no se escribe nada**: todo se agrega en memoria y se
vuelca una sola vez al final. Así el instrumento no altera lo que mide.

## Instalación

El orden importa: si `settings.php` apunta a un driver que no existe en disco,
el sitio no arranca.

### 1. Colocar y habilitar el módulo

```bash
cp -r db_audit web/modules/custom/db_audit
drush en db_audit -y
drush status   # debe seguir funcionando
```

### 2. Declarar una conexión separada para los resultados (recomendado)

Mantiene la medición limpia: las escrituras del auditor no tocan la base
auditada. Puede ser otro esquema en la misma instancia RDS.

```php
$databases['dbaudit']['default'] = [
  'driver' => 'mysql',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
  'database' => 'drupal_audit',
  'username' => 'xxx',
  'password' => 'xxx',
  'host' => 'db.interno.ejemplo',
  'port' => '3306',
  'prefix' => '',
];
```

Nota: esta conexión usa el driver `mysql` normal, **no** el instrumentado.

Si se omite, los resultados se guardan en la base auditada. Funciona igual: el
volcado es una sola sentencia por tabla y se excluye de las métricas.

### 3. Apuntar la conexión principal al driver instrumentado

Sobre el bloque `$databases['default']['default']` que ya exista, cambiar
`driver` y `namespace`, y añadir `autoload` y `dependencies`. El resto
(credenciales, host, prefijo) se deja igual.

```php
$databases['default']['default']['driver'] = 'auditmysql';
$databases['default']['default']['namespace'] = 'Drupal\\db_audit\\Driver\\Database\\auditmysql';
$databases['default']['default']['autoload'] = 'modules/custom/db_audit/src/Driver/Database/auditmysql/';
// Necesario para que el autoloader encuentre, antes de que exista el
// contenedor de servicios, tanto el driver mysql del que hereda como las
// clases de soporte del propio módulo.
$databases['default']['default']['dependencies'] = [
  'mysql' => [
    'namespace' => 'Drupal\\mysql',
    'autoload' => 'core/modules/mysql/src/',
  ],
  'db_audit' => [
    'namespace' => 'Drupal\\db_audit',
    'autoload' => 'modules/custom/db_audit/src/',
  ],
];
```

La entrada `db_audit` es una red de seguridad: el driver carga sus clases de
soporte por su cuenta si no está, pero declararla es lo correcto y evita
depender de esa carga manual.

`autoload` es relativo a la raíz de la aplicación (el directorio que contiene
`core/`). Ajústalo si el módulo no está en `modules/custom/`.

Si hay un target `replica`, aplícale lo mismo.

### 4. Activar la captura

```php
$settings['db_audit'] = [
  'enabled' => TRUE,
  'sample_rate' => 1,       // 1 = todos los requests. En producción, 100 o más.
  'backtrace_limit' => 30,  // 0 desactiva la atribución (más rápido, menos útil)
  'max_queries' => 5000,
  'timeline_limit' => 0,    // >0 guarda la secuencia de ejecuciones
  'capture_args' => FALSE,  // TRUE guarda valores: cuidado con datos personales
];
```

La variable de entorno `DB_AUDIT_ENABLED` (`1`/`0`) tiene prioridad sobre este
valor, para poder encender y apagar desde el entorno del contenedor sin
redesplegar.

El interruptor vive aquí y no en la configuración de Drupal porque la captura
empieza antes de que Drupal cargue su configuración.

### 5. Verificar

```bash
drush status
drush db-audit:diagnose   # debe decir "auditmysql (instrumentado)"
```

## Primer uso

```bash
# 1. Medir la latencia. Ejecutar desde el mismo host que sirve la aplicación:
#    el número tiene que reflejar lo que pagan los requests reales.
drush db-audit:rtt --samples=100

# 2. Etiquetar la corrida de referencia.
drush db-audit:tag base

# 3. Lanzar la carga sintética contra las rutas representativas.

# 4. Leer los resultados.
drush db-audit:summary
drush db-audit:routes
drush db-audit:findings
drush db-audit:modules
```

O en la interfaz: **Informes › Auditoría de base de datos**.

### Qué hacen exactamente esos dos comandos

**`db-audit:rtt --samples=N`** ejecuta `SELECT 1` contra la base de datos N veces
seguidas, cronometrando cada una. Como esa consulta el servidor la resuelve en un
tiempo despreciable, lo que se está midiendo es la ida y la vuelta por la red. Una
*muestra* es cada una de esas ejecuciones. Hacen falta muchas porque la latencia
varía entre consultas, y con dos o tres medidas puedes quedarte con un valor
atípico. Cien es suficiente y tarda menos de un segundo.

Como referencia se guarda la **mediana**, no el mínimo ni la media:

- El mínimo describe el suelo físico de la red, el mejor caso. Es un dato útil,
  pero subestima el coste real: al eliminar N consultas no ahorras N veces el
  mejor caso sino N veces el caso típico. En una prueba con jitter de ±0,4 ms, el
  mínimo bajó a 0,131 ms mientras la latencia real era de 1 ms — usarlo habría
  infravalorado el peso de la red en un factor de nueve.
- La media se desplaza detrás de cualquier pico aislado de contención.

**`db-audit:tag base`** no se queda escuchando nada ni deja ningún proceso: escribe
un valor en el *state* de Drupal y termina. Ese valor es la etiqueta con la que se
marcan los registros que se guarden a partir de ese momento, y persiste hasta que
lo cambies o lo borres con `drush db-audit:tag` sin argumento. Quien captura es el
driver instrumentado, que ya está activo en todos los requests. La etiqueta sólo
decide bajo qué nombre se archiva lo capturado.

## Cómo se leen los resultados

Lo primero que hay que mirar es el porcentaje de **% red** del resumen:

- **Por encima del 60–70%**: el tiempo se va en idas y vueltas. Optimizar SQL o
  añadir índices apenas moverá la aguja. Lo que hay que reducir es el *número*
  de consultas: `loadMultiple()`, agrupar en `IN()`, caché estática por request,
  y revisar que los bins de caché estén realmente en Redis.
- **Por debajo del 30–40%**: hay trabajo real en el servidor. Ahí sí aplican
  `EXPLAIN`, índices y reescritura de consultas.

Después, la pestaña **Hallazgos**, ordenada por milisegundos recuperables.

### Tipos de hallazgo

| Tipo | Qué significa | Por dónde tirar |
|---|---|---|
| `network_bound` | La mayoría del tiempo en BD es RTT | Reducir número de consultas, no optimizarlas |
| `n_plus_one` | Misma consulta repetida desde el mismo punto | `loadMultiple()`, `IN()`, precarga |
| `duplicate_query` | Ejecuciones idénticas con los mismos argumentos | Caché estática en memoria |
| `cache_in_database` | Tráfico contra tablas `cache_*` | Bins que no están yendo a Redis |
| `emulate_prepares_off` | Dos round-trips por consulta | Revisar el array `pdo` en settings.php |
| `connection_overhead` | Handshake caro en cada request | `PDO::ATTR_PERSISTENT` o un pooler de conexiones |
| `hot_infrastructure_table` | Tráfico a `sessions`, `watchdog`, `semaphore`… | Mover fuera de la BD |
| `slow_query` | Ejecución real lenta en el servidor | `EXPLAIN` e índices |
| `large_resultset` | Se traen demasiadas filas | `LIMIT`, paginación |
| `select_star` | `SELECT *` | Seleccionar sólo lo necesario |

### Comparar antes y después

```bash
drush db-audit:tag base
# ... carga ...
# aplicar un cambio
drush db-audit:tag con-persistent
# ... misma carga ...
drush db-audit:summary --tag=base
drush db-audit:summary --tag=con-persistent
```

Dos trampas al comparar corridas, ambas descubiertas probando el módulo:

- **La page cache falsea la segunda corrida.** Si repites las mismas URL como
  anónimo, la segunda tanda se sirve desde la caché de página *antes* del
  routing: verás pocas consultas y la ruta vacía, y concluirás que tu cambio
  fue milagroso. Usa un parámetro distinto en cada petición
  (`/node/1?cb=corrida2`), o autentícate, o vacía la caché entre corridas.
- **No midas el RTT en medio de una comparación.** La columna de red se calcula
  al guardar cada request con el RTT vigente en ese momento; si lo remides a
  mitad, las dos corridas dejan de ser comparables.

### Reproducir el escenario cross-AZ en local

Muy útil para validar una optimización antes de subirla: inyecta latencia
artificial en el contenedor de base de datos con `netem`. Con DDEV basta un
override para conceder `NET_ADMIN`:

```yaml
# .ddev/docker-compose.netem.yaml
services:
  db:
    cap_add:
      - NET_ADMIN
```

```bash
ddev restart
# OJO: el contenedor puede tener varias interfaces (eth0, eth1...) y hay que
# aplicar el retardo a la que resuelve el host de la base de datos. Añadir un
# servicio nuevo al proyecto (Redis, por ejemplo) cambia esa asignación, y
# netem sobre la interfaz equivocada no da error: simplemente no hace nada.
docker exec ddev-<proyecto>-web getent hosts db      # -> IP que usa la app
docker exec -u root ddev-<proyecto>-db ip -o -4 addr # -> qué interfaz la tiene

docker exec -u root ddev-<proyecto>-db sh -c \
  "apt-get update -qq && apt-get install -y -qq iproute2; \
   tc qdisc add dev ethN root netem delay 1ms"

# Comprobar SIEMPRE que ha surtido efecto antes de fiarse de las cifras:
docker exec ddev-<proyecto>-web ping -c 3 db
drush db-audit:rtt          # debe pasar de ~0,02 ms a ~1 ms

# para quitarlo:
docker exec -u root ddev-<proyecto>-db tc qdisc del dev ethN root
```

### Iterar sobre una ruta con curl

Activando *Publicar cabeceras* en la configuración:

```bash
curl -sS -o /dev/null -D - https://sitio/ruta | grep X-DbAudit
X-DbAudit-Queries: 412
X-DbAudit-Db-Ms: 470.11
X-DbAudit-Net-Ms: 428.48
X-DbAudit-Connect-Ms: 11.20
```

## Coste del instrumento

- Sin captura activa: una comprobación booleana por consulta. Despreciable.
- Con captura y `backtrace_limit: 30`: entre un 5% y un 15% de sobrecoste sobre
  el tiempo de base de datos. Es el precio de la atribución al *caller*.
- Con `backtrace_limit: 0`: sobrecoste casi nulo, pero se pierde el "quién".
- El volcado final son 2–3 sentencias.

En peticiones web el volcado ocurre en `kernel.terminate`. Con PHP-FPM eso es
después de enviar la respuesta al cliente. **Con Apache + mod_php no hay
`fastcgi_finish_request()`**, así que ahí sí suma al tiempo percibido; el
informe indica el coste para que puedas descontarlo.

## Seguridad y datos

- El SQL se guarda **normalizado**, con los literales sustituidos por `?`.
- Los argumentos **no** se guardan salvo que actives `capture_args`. No lo
  actives en un entorno con datos personales reales.
- Los informes requieren permiso explícito (`view db audit reports`).

## Revertir

Basta con quitar de `settings.php` las cuatro líneas del paso 3 (`driver`,
`namespace`, `autoload`, `dependencies`) para que la conexión vuelva al driver
`mysql` de core. La captura se detiene inmediatamente.

**Importante**: mientras `settings.php` apunte al driver instrumentado, no
desinstales ni borres el módulo — el sitio no arrancaría. Primero revierte
`settings.php`, después desinstala.

## Con Redis (u otra caché externa) por delante

Es el escenario para el que está pensado, y conviene saber cómo cambia la
lectura. El módulo intercepta a nivel de statement PDO, y Redis no pasa por
ahí, así que **no ve absolutamente nada del tráfico a Redis**: sólo mide lo que
llega de verdad a MariaDB. Eso no da métricas raras, pero sí desplaza dónde
está el problema.

Medido sobre el mismo tráfico y con 1 ms de latencia simulada, moviendo los
bins de caché de la base de datos a Redis:

| | Caché en BD | Caché en Redis |
|---|---|---|
| Consultas por request (media) | 83,8 | 29,9 |
| Tiempo en BD por request | 124,8 ms | 38,0 ms |
| Apertura de conexión | 6,94 ms | 6,21 ms |
| **Conexión como % del tiempo en BD** | **6%** | **16%** |
| Hallazgos `cache_in_database` | 21 | **0** |

Tres consecuencias prácticas:

1. **`cache_in_database` sirve de verificador de la configuración.** Con Redis
   bien puesto debe salir a cero. Si aparece, tienes un bin escapándose a la
   base de datos y pagando el salto entre zonas.
2. **El coste de conexión pasa a dominar.** Es un coste fijo por request que
   Redis no reduce: al desaparecer las consultas de caché, ese handshake pasa
   del 6% al 16% del tiempo en base de datos, y en los requests más ligeros
   llega a costar más que todas las consultas juntas. Con caché externa, la
   siguiente palanca ya no es reducir consultas sino eliminar el handshake:
   conexiones persistentes o un pooler.
3. **La media deja de representar a nadie.** La distribución se vuelve bimodal:
   en la medición anterior el 88% de los requests hacía menos de 20 consultas
   (mediana 9) mientras el peor hacía 778. Por eso el resumen incluye mediana y
   percentil 95: guíate por ellos y por el máximo de cada ruta, no por la
   media.

Lo que suele quedar en la base de datos con Redis activo, por orden de peso
medido: `key_value` (con diferencia el mayor, vía `DatabaseStorage`),
`menu_tree`, `config`, `router`, `path_alias` y las tablas de entidad. Ninguna
de ellas la cubre el backend de caché, así que son el siguiente objetivo real.

## Validación

Probado de extremo a extremo sobre Drupal 10.6.15 + MariaDB 10.11.16 en DDEV,
inyectando 1 ms de latencia con `netem` para reproducir el salto entre zonas.
Resultados de esa prueba, sobre la ruta `entity.node.canonical` con las mismas
107 consultas en ambos casos:

| | RTT 0,018 ms | RTT 1,042 ms |
|---|---|---|
| Tiempo en BD | 15,5 ms | 153,5 ms |
| De ello red | 2,0 ms (13%) | 111,3 ms (73%) |
| Apertura de conexión | 0,75 ms | 6,08 ms |
| Wall time | 50,9 ms | 230,8 ms |

Mismo código y mismas consultas: ×10 en tiempo de base de datos, y el módulo lo
atribuye correctamente a la red.

El experimento de conexiones persistentes sobre ese mismo escenario confirma el
modelo: el coste de conexión baja de 6,10 ms a 3,36 ms — se ahorra el handshake,
y los ~2,2 ms de los `init_commands` se siguen pagando, tal y como predice el
hallazgo `connection_overhead`.

## Limitaciones conocidas

- Sólo MySQL/MariaDB. Para PostgreSQL habría que replicar el driver sobre
  `Drupal\pgsql`.
- El tiempo medido es el que ve PHP: incluye red, ejecución y transferencia del
  resultado. Para separarlos se usa el RTT medido, que es una estimación, no una
  medición por consulta.
- `rows_total` depende de que las consultas se ejecuten con buffered queries
  (activo por defecto en el driver de core).
- Un `sample_rate` mayor que 1 muestrea por proceso, no por request: en PHP-FPM
  un proceso atiende un request, pero en Drush o en un queue worker un proceso
  cubre toda la ejecución.
