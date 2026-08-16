<?php

declare(strict_types=1);

namespace Drupal\db_audit;

use Drupal\Core\Site\Settings;

/**
 * Buffer en memoria de la actividad de base de datos del request en curso.
 *
 * Es deliberadamente estático y sin dependencias del contenedor de servicios:
 * el driver instrumentado empieza a registrar queries en el bootstrap, mucho
 * antes de que exista el contenedor, y también corre bajo Drush y en los queue
 * workers, donde el ciclo de vida es distinto.
 *
 * Durante el request no se escribe absolutamente nada: todo se agrega en
 * memoria y se vuelca una sola vez al final (ver AuditStorage). Esto es lo que
 * permite auditar sin que el propio auditor añada round-trips a la BD que
 * estamos midiendo.
 *
 * Interruptores, por orden de prioridad:
 * - Variable de entorno DB_AUDIT_ENABLED ("1"/"0"), pensada para poder
 *   encender y apagar la captura desde el entorno del contenedor sin
 *   redesplegar código.
 * - $settings['db_audit']['enabled'] en settings.php.
 * Por defecto: desactivado.
 */
final class Recorder {

  /**
   * Número máximo de caracteres de SQL que se conserva por query agregada.
   */
  private const SQL_MAX_LENGTH = 4000;

  private static bool $initialized = FALSE;

  private static bool $enabled = FALSE;

  /**
   * Pausa temporal, usada mientras el propio módulo escribe sus resultados.
   */
  private static bool $suspended = FALSE;

  private static array $config = [];

  /**
   * Agregados por (fingerprint del SQL normalizado + caller).
   */
  private static array $queries = [];

  /**
   * Contadores de ejecuciones exactamente idénticas (mismo SQL y mismos args).
   */
  private static array $exact = [];

  /**
   * Secuencia de ejecuciones, sólo si timeline_limit > 0.
   */
  private static array $timeline = [];

  private static int $totalQueries = 0;

  private static int $totalNs = 0;

  private static int $errorCount = 0;

  private static int $connectNs = 0;

  private static int $connectCount = 0;

  private static array $connectInfo = [];

  private static int $startNs = 0;

  private static string $requestId = '';

  private static bool $truncated = FALSE;

  /**
   * Metadatos que aporta más tarde el subscriber (ruta, código HTTP, etc.).
   */
  private static array $context = [];

  /**
   * Inicializa la configuración de captura una única vez por proceso.
   */
  private static function init(): void {
    if (self::$initialized) {
      return;
    }
    self::$initialized = TRUE;
    self::$startNs = hrtime(TRUE);

    $settings = [];
    try {
      $settings = Settings::get('db_audit', []);
      if (!is_array($settings)) {
        $settings = [];
      }
    }
    catch (\Throwable) {
      // Settings todavía no inicializado: se opera con los valores por
      // defecto, que dejan la captura apagada.
    }

    self::$config = $settings + [
      'enabled' => FALSE,
      'sample_rate' => 1,
      'max_queries' => 5000,
      'backtrace_limit' => 30,
      'timeline_limit' => 0,
      'capture_args' => FALSE,
      'targets' => [],
      'min_queries_to_store' => 1,
    ];

    $enabled = (bool) self::$config['enabled'];
    $env = getenv('DB_AUDIT_ENABLED');
    if ($env !== FALSE && $env !== '') {
      $enabled = in_array(strtolower((string) $env), ['1', 'true', 'yes', 'on'], TRUE);
    }

    // El muestreo se decide una sola vez por proceso, para que un request
    // quede capturado entero o no quede capturado en absoluto. Un muestreo
    // por query daría agregados sin sentido.
    $rate = max(1, (int) self::$config['sample_rate']);
    if ($enabled && $rate > 1) {
      try {
        $enabled = random_int(1, $rate) === 1;
      }
      catch (\Throwable) {
        $enabled = FALSE;
      }
    }

    self::$enabled = $enabled;

    if (self::$enabled) {
      try {
        self::$requestId = bin2hex(random_bytes(8));
      }
      catch (\Throwable) {
        self::$requestId = substr(hash('sha256', (string) hrtime(TRUE) . getmypid()), 0, 16);
      }

      // Red de seguridad para todo lo que no es una petición web: Drush, cron,
      // queue workers y errores fatales, donde KernelEvents::TERMINATE no
      // llega a dispararse. El volcado es idempotente, así que en web
      // simplemente no hace nada porque el evento ya lo hizo antes.
      register_shutdown_function([self::class, 'onShutdown']);
    }
  }

  /**
   * Cierra la auditoría al terminar el proceso PHP.
   */
  public static function onShutdown(): void {
    try {
      if (\Drupal::hasContainer() && \Drupal::hasService('db_audit.flusher')) {
        \Drupal::service('db_audit.flusher')->flush(['closed_by' => 'shutdown']);
      }
    }
    catch (\Throwable) {
      // En el shutdown ya no hay a quién reportar; se ignora.
    }
  }

  /**
   * Indica si la captura está activa. Está en el camino caliente: barato.
   */
  public static function isEnabled(): bool {
    if (!self::$initialized) {
      self::init();
    }
    return self::$enabled && !self::$suspended;
  }

  /**
   * Profundidad máxima de pila a capturar (0 desactiva la atribución).
   */
  public static function backtraceLimit(): int {
    if (!self::$initialized) {
      self::init();
    }
    return (int) (self::$config['backtrace_limit'] ?? 30);
  }

  /**
   * Registra el coste de abrir una conexión.
   */
  public static function recordConnect(int $elapsedNs, array $info = []): void {
    if (!self::isEnabled()) {
      return;
    }
    self::$connectNs += $elapsedNs;
    self::$connectCount++;
    // Nos quedamos con los datos de la primera conexión: los atributos PDO
    // efectivos son los mismos para todas y sólo interesan como diagnóstico.
    if (self::$connectInfo === []) {
      self::$connectInfo = $info;
    }
  }

  /**
   * Registra una ejecución de statement.
   *
   * @param string $sql
   *   SQL tal y como se envió al servidor.
   * @param array $args
   *   Argumentos vinculados.
   * @param int $elapsedNs
   *   Tiempo de ejecución en nanosegundos.
   * @param array $caller
   *   Caller resuelto por Connection::findCallerFromDebugBacktrace().
   * @param string $target
   *   Target de la conexión ('default', 'replica'...).
   * @param bool $inTransaction
   *   Si la ejecución ocurrió dentro de una transacción abierta.
   * @param int|null $rows
   *   Filas afectadas o devueltas, si se pudo determinar.
   * @param string|null $error
   *   Mensaje de error si la ejecución falló.
   */
  public static function recordQuery(string $sql, array $args, int $elapsedNs, array $caller, string $target, bool $inTransaction, ?int $rows = NULL, ?string $error = NULL): void {
    if (!self::isEnabled()) {
      return;
    }

    $targets = self::$config['targets'] ?? [];
    if (is_array($targets) && $targets !== [] && !in_array($target, $targets, TRUE)) {
      return;
    }

    self::$totalQueries++;
    self::$totalNs += $elapsedNs;
    if ($error !== NULL) {
      self::$errorCount++;
    }

    $normalized = QueryNormalizer::normalize($sql);
    $fingerprint = QueryNormalizer::fingerprint($normalized);
    $caller_info = CallerContext::fromBacktraceEntry($caller);

    $key = $fingerprint . '|' . $caller_info['file'] . ':' . $caller_info['line'];

    if (!isset(self::$queries[$key])) {
      // Al alcanzar el límite se dejan de crear agregados nuevos, pero se
      // siguen contando los existentes y los contadores globales, para que el
      // total de queries del request siga siendo exacto.
      if (count(self::$queries) >= (int) self::$config['max_queries']) {
        self::$truncated = TRUE;
        return;
      }
      self::$queries[$key] = [
        'fingerprint' => $fingerprint,
        'sql' => mb_substr($normalized, 0, self::SQL_MAX_LENGTH),
        'operation' => QueryNormalizer::operation($normalized),
        'tables' => QueryNormalizer::tables($normalized),
        'caller_file' => $caller_info['file'],
        'caller_line' => $caller_info['line'],
        'caller_class' => $caller_info['class'],
        'caller_function' => $caller_info['function'],
        'module' => $caller_info['module'],
        'target' => $target,
        'count' => 0,
        'time_ns' => 0,
        'max_ns' => 0,
        'min_ns' => PHP_INT_MAX,
        'rows' => 0,
        'in_transaction' => 0,
        'errors' => 0,
        'duplicates' => 0,
      ];
    }

    $agg = &self::$queries[$key];
    $agg['count']++;
    $agg['time_ns'] += $elapsedNs;
    $agg['max_ns'] = max($agg['max_ns'], $elapsedNs);
    $agg['min_ns'] = min($agg['min_ns'], $elapsedNs);
    if ($rows !== NULL) {
      $agg['rows'] += $rows;
    }
    if ($inTransaction) {
      $agg['in_transaction']++;
    }
    if ($error !== NULL) {
      $agg['errors']++;
    }

    // Ejecuciones exactamente idénticas (mismo SQL y mismos argumentos): son
    // resultado repetido dentro del mismo request y por tanto candidatas
    // directas a una caché estática en memoria. Distinto de un N+1, donde los
    // argumentos varían.
    $exact_hash = hash('xxh3', $sql . '|' . self::serializeArgs($args));
    if (isset(self::$exact[$exact_hash])) {
      self::$exact[$exact_hash]++;
      $agg['duplicates']++;
    }
    else {
      self::$exact[$exact_hash] = 1;
    }

    unset($agg);

    $timeline_limit = (int) self::$config['timeline_limit'];
    if ($timeline_limit > 0 && count(self::$timeline) < $timeline_limit) {
      self::$timeline[] = [
        'seq' => self::$totalQueries,
        'fingerprint' => $fingerprint,
        'time_ns' => $elapsedNs,
        'target' => $target,
        'in_transaction' => $inTransaction,
        'caller' => $caller_info['file'] . ':' . $caller_info['line'],
        'args' => !empty(self::$config['capture_args']) ? self::serializeArgs($args) : NULL,
      ];
    }
  }

  /**
   * Serializa argumentos de forma estable y acotada.
   */
  private static function serializeArgs(array $args): string {
    if ($args === []) {
      return '';
    }
    ksort($args);
    $parts = [];
    foreach ($args as $name => $value) {
      if (is_scalar($value) || $value === NULL) {
        $parts[] = $name . '=' . (is_bool($value) ? (int) $value : (string) $value);
      }
      else {
        $parts[] = $name . '=?';
      }
    }
    return mb_substr(implode('&', $parts), 0, 1000);
  }

  /**
   * Añade metadatos del request (ruta, código HTTP, usuario...).
   */
  public static function addContext(array $context): void {
    if (!self::$initialized) {
      self::init();
    }
    if (!self::$enabled) {
      return;
    }
    self::$context = $context + self::$context;
  }

  /**
   * Devuelve todo lo acumulado en este request.
   *
   * Comprueba $enabled y no isEnabled() a propósito: el volcado ocurre con la
   * captura suspendida, y aun así hay que poder leer lo capturado.
   */
  public static function snapshot(): array {
    if (!self::$initialized) {
      self::init();
    }
    if (!self::$enabled) {
      return [];
    }

    $queries = self::$queries;
    foreach ($queries as &$agg) {
      if ($agg['min_ns'] === PHP_INT_MAX) {
        $agg['min_ns'] = 0;
      }
    }
    unset($agg);

    return [
      'request_id' => self::$requestId,
      'sapi' => PHP_SAPI,
      'wall_ns' => hrtime(TRUE) - self::$startNs,
      'total_queries' => self::$totalQueries,
      'distinct_queries' => count(self::$queries),
      'total_db_ns' => self::$totalNs,
      'connect_ns' => self::$connectNs,
      'connect_count' => self::$connectCount,
      'connect_info' => self::$connectInfo,
      'error_count' => self::$errorCount,
      'truncated' => self::$truncated,
      'peak_memory' => memory_get_peak_usage(TRUE),
      'queries' => $queries,
      'timeline' => self::$timeline,
      'context' => self::$context,
    ];
  }

  /**
   * Número de queries registradas hasta el momento.
   */
  public static function getQueryCount(): int {
    return self::$totalQueries;
  }

  /**
   * Tiempo total acumulado en base de datos, en nanosegundos.
   */
  public static function getTotalNs(): int {
    return self::$totalNs;
  }

  /**
   * Tiempo acumulado abriendo conexiones, en nanosegundos.
   */
  public static function getConnectNs(): int {
    return self::$connectNs;
  }

  /**
   * Identificador del request en curso.
   */
  public static function getRequestId(): string {
    return self::$requestId;
  }

  /**
   * Pausa la captura.
   *
   * Lo usa el almacenamiento antes de volcar, para que las escrituras del
   * propio módulo no se auditen a sí mismas cuando comparten la conexión
   * instrumentada. Es reversible porque en procesos largos (Drush, queue
   * workers) hay que seguir auditando después del volcado.
   */
  public static function suspend(): void {
    self::$suspended = TRUE;
  }

  /**
   * Reanuda la captura tras un suspend().
   */
  public static function resume(): void {
    self::$suspended = FALSE;
  }

  /**
   * Vacía el buffer. Necesario en procesos largos (Drush, queue workers) que
   * auditan varias unidades de trabajo dentro del mismo proceso PHP.
   */
  public static function reset(): void {
    self::$queries = [];
    self::$exact = [];
    self::$timeline = [];
    self::$totalQueries = 0;
    self::$totalNs = 0;
    self::$errorCount = 0;
    self::$connectNs = 0;
    self::$connectCount = 0;
    self::$truncated = FALSE;
    self::$context = [];
    self::$startNs = hrtime(TRUE);
    try {
      self::$requestId = bin2hex(random_bytes(8));
    }
    catch (\Throwable) {
      self::$requestId = substr(hash('sha256', (string) hrtime(TRUE) . getmypid()), 0, 16);
    }
  }

}
