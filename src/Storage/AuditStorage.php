<?php

declare(strict_types=1);

namespace Drupal\db_audit\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\State\StateInterface;
use Drupal\db_audit\Analysis\Finding;
use Drupal\db_audit\Recorder;

/**
 * Persiste los resultados de la auditoría y sirve las consultas del informe.
 *
 * Toda la escritura ocurre en una única llamada al final del request, y a ser
 * posible contra una conexión distinta de la auditada. La razón es que el
 * instrumento no debe alterar lo que mide: si el auditor escribiera consulta a
 * consulta contra la misma conexión, cada request auditado pagaría cientos de
 * round-trips adicionales y las cifras dejarían de representar al sitio real.
 *
 * Si existe una conexión con la clave 'dbaudit' en settings.php se usa esa; en
 * caso contrario se cae a la conexión por defecto, que sigue siendo válido
 * porque el volcado son dos o tres sentencias, pero conviene saberlo.
 */
class AuditStorage {

  /**
   * Clave de conexión reservada para el almacenamiento de auditoría.
   */
  public const CONNECTION_KEY = 'dbaudit';

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly StateInterface $state,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * Conexión donde se guardan los resultados.
   */
  public static function connection(): Connection {
    $info = Database::getConnectionInfo(self::CONNECTION_KEY);
    if (!empty($info)) {
      return Database::getConnection('default', self::CONNECTION_KEY);
    }
    return Database::getConnection();
  }

  /**
   * Indica si el almacenamiento va a una conexión separada de la auditada.
   */
  public static function isolated(): bool {
    return !empty(Database::getConnectionInfo(self::CONNECTION_KEY));
  }

  /**
   * Vuelca el resultado de un request auditado.
   *
   * @param array $snapshot
   *   Snapshot del Recorder.
   * @param \Drupal\db_audit\Analysis\Finding[] $findings
   *   Hallazgos del analizador.
   */
  public function write(array $snapshot, array $findings): void {
    if (empty($snapshot) || empty($snapshot['request_id'])) {
      return;
    }

    $config = $this->configFactory->get('db_audit.settings');
    $total_queries = (int) ($snapshot['total_queries'] ?? 0);

    // Requests triviales (una redirección, un fichero servido por Drupal) sólo
    // añaden ruido al informe.
    $min = (int) ($config->get('min_queries_to_store') ?? 1);
    if ($total_queries < max(1, $min)) {
      return;
    }

    // Las páginas de informe del propio módulo son requests como cualquier
    // otro y se auditan solas, pero al aparecer en las tablas compiten con las
    // rutas del sitio y empeoran la señal. Se excluyen salvo que se pida lo
    // contrario.
    if (($config->get('exclude_own_routes') ?? TRUE)
      && str_starts_with((string) ($snapshot['context']['route'] ?? ''), 'db_audit.')) {
      return;
    }

    // A partir de aquí el propio módulo va a ejecutar consultas. Si comparten
    // conexión con lo auditado, se contarían a sí mismas.
    Recorder::suspend();

    try {
      try {
        $this->doWrite($snapshot, $findings, $config, $total_queries);
      }
      catch (\Throwable $e) {
        // Caso habitual y esperable: el módulo se instaló antes de declarar la
        // conexión 'dbaudit' en settings.php, así que hook_install() creó las
        // tablas en la base auditada y no en la de auditoría. Es justo el orden
        // que recomienda el README, de modo que conviene resolverlo solo en
        // lugar de exigir una reinstalación del módulo.
        if (!self::isMissingTableError($e)) {
          throw $e;
        }
        self::ensureSchema();
        $this->doWrite($snapshot, $findings, $config, $total_queries);
      }
    }
    catch (\Throwable $e) {
      // Un fallo del auditor no puede tumbar el sitio auditado. Se registra y
      // se sigue.
      \Drupal::logger('db_audit')->error('No se pudo guardar la auditoría del request @id: @message', [
        '@id' => $snapshot['request_id'] ?? '?',
        '@message' => $e->getMessage(),
      ]);
    }
    finally {
      Recorder::resume();
    }
  }

  /**
   * Etiqueta con la que se marca lo que se captura.
   *
   * La variable de entorno tiene prioridad sobre el valor global guardado en
   * state porque en un despliegue con varias instancias el state es compartido: no
   * permite distinguir de dónde salió cada medición. Poniendo la etiqueta en
   * el entorno de cada instancia se puede separar por zona de disponibilidad,
   * que es justo lo que hay que comparar cuando la aplicación y la base de
   * datos están repartidas entre zonas y el RTT no es el mismo desde todas.
   */
  protected function runTag(): string {
    $env = getenv('DB_AUDIT_RUN_TAG');
    if ($env !== FALSE && trim((string) $env) !== '') {
      return trim((string) $env);
    }
    return (string) $this->state->get('db_audit.run_tag', '');
  }

  /**
   * Indica si una excepción se debe a que falta una tabla de auditoría.
   */
  protected static function isMissingTableError(\Throwable $e): bool {
    // SQLSTATE 42S02 / error 1146 de MySQL: "base table or view not found".
    // Se acota a estos códigos a propósito: cualquier otro fallo debe
    // propagarse y registrarse, no disparar una creación de esquema.
    $message = $e->getMessage();
    return str_contains($message, '42S02') || str_contains($message, '1146');
  }

  /**
   * Escritura efectiva del request, sus consultas y sus hallazgos.
   */
  protected function doWrite(array $snapshot, array $findings, $config, int $total_queries): void {
    $connection = self::connection();
    $now = $this->time->getRequestTime();
    $request_id = (string) $snapshot['request_id'];
    $context = $snapshot['context'] ?? [];
    $route = mb_substr((string) ($context['route'] ?? ''), 0, 255);
    $run_tag = $this->runTag();
    $rtt_ns = (int) $this->state->get('db_audit.rtt_ns', 0);

    $saving = 0.0;
    foreach ($findings as $finding) {
      $saving += $finding->savingMs;
    }

    $connection->insert('db_audit_request')
      ->fields([
        'request_id' => $request_id,
        'created' => $now,
        'run_tag' => $run_tag !== '' ? mb_substr($run_tag, 0, 64) : NULL,
        'sapi' => mb_substr((string) ($snapshot['sapi'] ?? ''), 0, 32),
        'hostname' => mb_substr((string) ($context['hostname'] ?? gethostname() ?: ''), 0, 128),
        'route' => $route,
        'path' => mb_substr((string) ($context['path'] ?? ''), 0, 512),
        'method' => mb_substr((string) ($context['method'] ?? ''), 0, 10),
        'status' => (int) ($context['status'] ?? 0),
        'uid' => (int) ($context['uid'] ?? 0),
        'is_authenticated' => !empty($context['authenticated']) ? 1 : 0,
        'total_queries' => $total_queries,
        'distinct_queries' => (int) ($snapshot['distinct_queries'] ?? 0),
        'total_db_ms' => ((int) ($snapshot['total_db_ns'] ?? 0)) / 1e6,
        'network_ms' => ($total_queries * $rtt_ns) / 1e6,
        'connect_ms' => ((int) ($snapshot['connect_ns'] ?? 0)) / 1e6,
        'connect_count' => (int) ($snapshot['connect_count'] ?? 0),
        'wall_ms' => ((int) ($snapshot['wall_ns'] ?? 0)) / 1e6,
        'peak_memory' => (int) ($snapshot['peak_memory'] ?? 0),
        'error_count' => (int) ($snapshot['error_count'] ?? 0),
        'saving_ms' => $saving,
        'truncated' => !empty($snapshot['truncated']) ? 1 : 0,
      ])
      ->execute();

    if ($config->get('store_queries') ?? TRUE) {
      $this->writeQueries($connection, $snapshot, $request_id, $route, $run_tag, $now, (int) ($config->get('max_stored_queries') ?? 200));
    }

    $this->writeFindings($connection, $findings, $request_id, $route, $run_tag, $now);
  }

  /**
   * Escribe las consultas agregadas en una sola sentencia multi-fila.
   */
  protected function writeQueries(Connection $connection, array $snapshot, string $request_id, string $route, string $run_tag, int $now, int $limit): void {
    $queries = $snapshot['queries'] ?? [];
    if ($queries === []) {
      return;
    }

    // Cuando hay más agregados que el límite, se conservan los que más veces
    // se ejecutaron: son los que explican el volumen y los que contienen los
    // N+1. Descartar por tiempo dejaría fuera precisamente el patrón que
    // buscamos, porque cada ejecución individual de un N+1 es rapidísima.
    if (count($queries) > $limit) {
      uasort($queries, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
      $queries = array_slice($queries, 0, $limit, TRUE);
    }

    $insert = $connection->insert('db_audit_query')->fields([
      'request_id', 'created', 'route', 'run_tag', 'fingerprint', 'sql',
      'operation', 'tables', 'caller_file', 'caller_line', 'caller_class',
      'caller_function', 'module', 'target', 'exec_count', 'time_ms',
      'max_ms', 'min_ms', 'rows_total', 'duplicates', 'errors',
    ]);

    foreach ($queries as $agg) {
      $insert->values([
        'request_id' => $request_id,
        'created' => $now,
        'route' => $route,
        'run_tag' => $run_tag !== '' ? mb_substr($run_tag, 0, 64) : NULL,
        'fingerprint' => $agg['fingerprint'],
        'sql' => $agg['sql'],
        'operation' => $agg['operation'],
        'tables' => $agg['tables'],
        'caller_file' => $agg['caller_file'],
        'caller_line' => $agg['caller_line'],
        'caller_class' => $agg['caller_class'],
        'caller_function' => $agg['caller_function'],
        'module' => $agg['module'],
        'target' => $agg['target'],
        'exec_count' => $agg['count'],
        'time_ms' => $agg['time_ns'] / 1e6,
        'max_ms' => $agg['max_ns'] / 1e6,
        'min_ms' => $agg['min_ns'] / 1e6,
        'rows_total' => max(0, (int) $agg['rows']),
        'duplicates' => $agg['duplicates'],
        'errors' => $agg['errors'],
      ]);
    }

    $insert->execute();
  }

  /**
   * Escribe los hallazgos.
   */
  protected function writeFindings(Connection $connection, array $findings, string $request_id, string $route, string $run_tag, int $now): void {
    if ($findings === []) {
      return;
    }

    $insert = $connection->insert('db_audit_finding')->fields([
      'request_id', 'created', 'route', 'run_tag', 'type', 'severity',
      'title', 'detail', 'saving_ms', 'fingerprint', 'module', 'caller',
    ]);

    foreach ($findings as $finding) {
      $insert->values([
        'request_id' => $request_id,
        'created' => $now,
        'route' => $route,
        'run_tag' => $run_tag !== '' ? mb_substr($run_tag, 0, 64) : NULL,
        'type' => $finding->type,
        'severity' => $finding->severity,
        'title' => mb_substr($finding->title, 0, 512),
        'detail' => $finding->detail,
        'saving_ms' => $finding->savingMs,
        'fingerprint' => $finding->fingerprint,
        'module' => mb_substr($finding->module, 0, 128),
        'caller' => mb_substr($finding->caller, 0, 512),
      ]);
    }

    $insert->execute();
  }

  /**
   * Rutas ordenadas por consultas por request.
   */
  public function topRoutes(int $limit = 50, ?string $run_tag = NULL): array {
    $connection = self::connection();
    $query = $connection->select('db_audit_request', 'r')
      ->fields('r', ['route'])
      ->groupBy('r.route')
      ->orderBy('avg_queries', 'DESC')
      ->range(0, $limit);

    $query->addExpression('COUNT(r.id)', 'samples');
    $query->addExpression('AVG(r.total_queries)', 'avg_queries');
    $query->addExpression('MAX(r.total_queries)', 'max_queries');
    $query->addExpression('AVG(r.total_db_ms)', 'avg_db_ms');
    $query->addExpression('AVG(r.network_ms)', 'avg_network_ms');
    $query->addExpression('AVG(r.connect_ms)', 'avg_connect_ms');
    $query->addExpression('AVG(r.saving_ms)', 'avg_saving_ms');

    if ($run_tag !== NULL && $run_tag !== '') {
      $query->condition('r.run_tag', $run_tag);
    }

    return $query->execute()->fetchAll();
  }

  /**
   * Hallazgos agrupados, ordenados por impacto acumulado.
   */
  public function topFindings(int $limit = 100, ?string $run_tag = NULL, ?string $route = NULL): array {
    $connection = self::connection();
    // Se agrupa por tipo, severidad, módulo y punto de llamada, pero NO por
    // título ni detalle: esos textos llevan cifras concretas de cada request
    // ("263 consultas a tablas de caché"), así que incluirlos en el GROUP BY
    // impide que agrupen y llena el informe de filas casi idénticas.
    $query = $connection->select('db_audit_finding', 'f')
      ->fields('f', ['type', 'severity', 'module', 'caller'])
      ->groupBy('f.type')
      ->groupBy('f.severity')
      ->groupBy('f.module')
      ->groupBy('f.caller')
      ->range(0, $limit);

    $query->addExpression('MAX(f.title)', 'title');
    $query->addExpression('MAX(f.detail)', 'detail');
    $query->addExpression('COUNT(f.id)', 'occurrences');
    $query->addExpression('SUM(f.saving_ms)', 'total_saving');
    $query->addExpression('AVG(f.saving_ms)', 'avg_saving');
    // La severidad manda sobre el ahorro. Sin esto, los hallazgos de
    // diagnóstico puro —network_bound sobre todo, que no propone un ahorro
    // concreto sino que explica de qué va el problema— quedarían enterrados al
    // final de la tabla, que es justo donde nadie los lee.
    $query->addExpression("MAX(FIELD(f.severity, 'low', 'medium', 'high', 'critical'))", 'severity_weight');
    $query->orderBy('severity_weight', 'DESC');
    $query->orderBy('total_saving', 'DESC');

    if ($run_tag !== NULL && $run_tag !== '') {
      $query->condition('f.run_tag', $run_tag);
    }
    if ($route !== NULL && $route !== '') {
      $query->condition('f.route', $route);
    }

    return $query->execute()->fetchAll();
  }

  /**
   * Consultas más repetidas, agrupadas por huella y punto de llamada.
   */
  public function topQueries(int $limit = 100, ?string $run_tag = NULL, ?string $route = NULL, ?string $module = NULL): array {
    $connection = self::connection();
    $query = $connection->select('db_audit_query', 'q')
      ->fields('q', ['fingerprint', 'sql', 'operation', 'tables', 'module', 'caller_file', 'caller_line'])
      ->groupBy('q.fingerprint')
      ->groupBy('q.sql')
      ->groupBy('q.operation')
      ->groupBy('q.tables')
      ->groupBy('q.module')
      ->groupBy('q.caller_file')
      ->groupBy('q.caller_line')
      ->orderBy('total_exec', 'DESC')
      ->range(0, $limit);

    $query->addExpression('SUM(q.exec_count)', 'total_exec');
    $query->addExpression('SUM(q.time_ms)', 'total_ms');
    $query->addExpression('MAX(q.max_ms)', 'worst_ms');
    $query->addExpression('AVG(q.exec_count)', 'avg_exec_per_request');
    $query->addExpression('COUNT(DISTINCT q.request_id)', 'requests');
    $query->addExpression('SUM(q.duplicates)', 'duplicates');

    if ($run_tag !== NULL && $run_tag !== '') {
      $query->condition('q.run_tag', $run_tag);
    }
    if ($route !== NULL && $route !== '') {
      $query->condition('q.route', $route);
    }
    if ($module !== NULL && $module !== '') {
      $query->condition('q.module', $module);
    }

    return $query->execute()->fetchAll();
  }

  /**
   * Reparto de consultas por módulo responsable.
   */
  public function byModule(int $limit = 50, ?string $run_tag = NULL): array {
    $connection = self::connection();
    $query = $connection->select('db_audit_query', 'q')
      ->fields('q', ['module'])
      ->groupBy('q.module')
      ->orderBy('total_exec', 'DESC')
      ->range(0, $limit);

    $query->addExpression('SUM(q.exec_count)', 'total_exec');
    $query->addExpression('SUM(q.time_ms)', 'total_ms');
    $query->addExpression('COUNT(DISTINCT q.fingerprint)', 'distinct_queries');
    $query->addExpression('COUNT(DISTINCT q.request_id)', 'requests');

    if ($run_tag !== NULL && $run_tag !== '') {
      $query->condition('q.run_tag', $run_tag);
    }

    return $query->execute()->fetchAll();
  }

  /**
   * Requests recientes.
   */
  public function recentRequests(int $limit = 50, ?string $run_tag = NULL, ?string $route = NULL): array {
    $connection = self::connection();
    $query = $connection->select('db_audit_request', 'r')
      ->fields('r')
      ->orderBy('r.created', 'DESC')
      ->orderBy('r.id', 'DESC')
      ->range(0, $limit);

    if ($run_tag !== NULL && $run_tag !== '') {
      $query->condition('r.run_tag', $run_tag);
    }
    if ($route !== NULL && $route !== '') {
      $query->condition('r.route', $route);
    }

    return $query->execute()->fetchAll();
  }

  /**
   * Detalle completo de un request.
   */
  public function requestDetail(string $request_id): array {
    $connection = self::connection();

    $request = $connection->select('db_audit_request', 'r')
      ->fields('r')
      ->condition('r.request_id', $request_id)
      ->execute()
      ->fetchAssoc();

    if (!$request) {
      return [];
    }

    $queries = $connection->select('db_audit_query', 'q')
      ->fields('q')
      ->condition('q.request_id', $request_id)
      ->orderBy('q.exec_count', 'DESC')
      ->orderBy('q.time_ms', 'DESC')
      ->execute()
      ->fetchAll();

    $findings = $connection->select('db_audit_finding', 'f')
      ->fields('f')
      ->condition('f.request_id', $request_id)
      ->orderBy('f.saving_ms', 'DESC')
      ->execute()
      ->fetchAll();

    return [
      'request' => $request,
      'queries' => $queries,
      'findings' => $findings,
    ];
  }

  /**
   * Etiquetas de corrida disponibles.
   */
  public function runTags(): array {
    $connection = self::connection();
    $tags = $connection->select('db_audit_request', 'r')
      ->fields('r', ['run_tag'])
      ->isNotNull('r.run_tag')
      ->distinct()
      ->orderBy('r.run_tag')
      ->execute()
      ->fetchCol();

    return array_values(array_filter($tags));
  }

  /**
   * Resumen global de una corrida.
   */
  public function summary(?string $run_tag = NULL): array {
    $connection = self::connection();
    $query = $connection->select('db_audit_request', 'r');
    $query->addExpression('COUNT(r.id)', 'requests');
    $query->addExpression('SUM(r.total_queries)', 'queries');
    $query->addExpression('AVG(r.total_queries)', 'avg_queries');
    $query->addExpression('AVG(r.total_db_ms)', 'avg_db_ms');
    $query->addExpression('AVG(r.network_ms)', 'avg_network_ms');
    $query->addExpression('AVG(r.connect_ms)', 'avg_connect_ms');
    $query->addExpression('SUM(r.saving_ms)', 'total_saving');
    $query->addExpression('MIN(r.created)', 'first');
    $query->addExpression('MAX(r.created)', 'last');

    if ($run_tag !== NULL && $run_tag !== '') {
      $query->condition('r.run_tag', $run_tag);
    }

    return (array) $query->execute()->fetchAssoc();
  }

  /**
   * Distribución de consultas por request.
   *
   * Con una caché externa como Redis por delante, el reparto se vuelve
   * marcadamente bimodal: la inmensa mayoría de los requests resuelve casi
   * todo desde caché y toca la base de datos un puñado de veces, mientras que
   * unos pocos —caché fría, invalidaciones, páginas no cacheables— disparan
   * cientos de consultas. En ese escenario la media no describe a casi ningún
   * request real, así que hace falta la mediana y el percentil 95 para no
   * sacar conclusiones equivocadas.
   *
   * Se calcula en PHP porque la mediana agrupada es incómoda en SQL y aquí el
   * volumen es el de un entorno de preproducción, no el de producción.
   */
  public function queryDistribution(?string $run_tag = NULL, int $sample_limit = 10000): array {
    $connection = self::connection();
    $query = $connection->select('db_audit_request', 'r')
      ->fields('r', ['total_queries'])
      ->orderBy('r.id', 'DESC')
      ->range(0, $sample_limit);

    if ($run_tag !== NULL && $run_tag !== '') {
      $query->condition('r.run_tag', $run_tag);
    }

    $values = array_map('intval', $query->execute()->fetchCol());
    if ($values === []) {
      return [];
    }

    sort($values, SORT_NUMERIC);
    $count = count($values);

    return [
      'count' => $count,
      'min' => $values[0],
      'median' => $values[intdiv($count, 2)],
      'p95' => $values[min($count - 1, (int) floor($count * 0.95))],
      'max' => $values[$count - 1],
      // Proporción de requests que apenas tocan la base de datos: es la señal
      // de que la caché externa está haciendo su trabajo.
      'light_share' => count(array_filter($values, static fn(int $v): bool => $v < 20)) / $count,
    ];
  }

  /**
   * Borra datos anteriores a una marca de tiempo.
   */
  public function purge(?int $before = NULL, ?string $run_tag = NULL): int {
    Recorder::suspend();
    try {
      $connection = self::connection();
      $deleted = 0;
      foreach (['db_audit_request', 'db_audit_query', 'db_audit_finding'] as $table) {
        $delete = $connection->delete($table);
        if ($before !== NULL) {
          $delete->condition('created', $before, '<');
        }
        if ($run_tag !== NULL && $run_tag !== '') {
          $delete->condition('run_tag', $run_tag);
        }
        if ($before === NULL && ($run_tag === NULL || $run_tag === '')) {
          // Sin filtros: se vacía la tabla entera.
          $connection->truncate($table)->execute();
          continue;
        }
        $deleted += (int) $delete->execute();
      }
      return $deleted;
    }
    finally {
      Recorder::resume();
    }
  }

  /**
   * Crea las tablas en la conexión de auditoría si están en otra base.
   */
  public static function ensureSchema(): void {
    if (!self::isolated()) {
      // hook_schema() ya las creó en la conexión por defecto.
      return;
    }

    \Drupal::moduleHandler()->loadInclude('db_audit', 'install');
    $schema = db_audit_schema();
    $connection = self::connection();

    foreach ($schema as $name => $definition) {
      if (!$connection->schema()->tableExists($name)) {
        $connection->schema()->createTable($name, $definition);
      }
    }
  }

  /**
   * Elimina las tablas de la conexión separada.
   */
  public static function dropSchema(): void {
    if (!self::isolated()) {
      return;
    }

    $connection = self::connection();
    foreach (['db_audit_request', 'db_audit_query', 'db_audit_finding'] as $table) {
      if ($connection->schema()->tableExists($table)) {
        $connection->schema()->dropTable($table);
      }
    }
  }

}
