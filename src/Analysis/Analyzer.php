<?php

declare(strict_types=1);

namespace Drupal\db_audit\Analysis;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Convierte la captura en bruto en hallazgos priorizados por impacto.
 *
 * El criterio de priorización es la latencia de red medida (RTT), no el
 * tiempo de ejecución en el servidor. En una topología donde la aplicación y
 * la base de datos están en zonas de disponibilidad distintas, el coste
 * dominante no suele ser el SQL lento sino el número de idas y vueltas: una
 * consulta que el servidor resuelve en 0,05 ms cuesta 1,05 ms si el RTT es de
 * 1 ms, y repetirla 300 veces cuesta 315 ms de los que 300 son puramente red.
 *
 * Por eso cada hallazgo se traduce a milisegundos ahorrables por request:
 * es lo que permite decidir si compensa tocar un N+1 de 300 consultas o una
 * única consulta lenta.
 */
class Analyzer {

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly StateInterface $state,
  ) {}

  /**
   * Tablas de infraestructura cuyo tráfico conviene vigilar de cerca.
   *
   * En una migración con Redis por delante, ver tráfico contra cache_* es la
   * señal inequívoca de que algún bin no está yendo a Redis y sigue pagando
   * el salto entre zonas.
   */
  protected const INFRA_TABLES = [
    'sessions' => 'sesiones',
    'watchdog' => 'log de Drupal (dblog)',
    'semaphore' => 'semáforos',
    'flood' => 'control de flood',
    'key_value_expire' => 'key-value con expiración',
    'queue' => 'cola por defecto',
  ];

  /**
   * Analiza un snapshot del Recorder y devuelve los hallazgos ordenados.
   *
   * @return \Drupal\db_audit\Analysis\Finding[]
   */
  public function analyze(array $snapshot): array {
    $config = $this->configFactory->get('db_audit.settings');
    $rtt_ns = (int) $this->state->get('db_audit.rtt_ns', 0);
    $rtt_ms = $rtt_ns / 1e6;

    $findings = [];
    $total_queries = (int) ($snapshot['total_queries'] ?? 0);

    if ($total_queries === 0) {
      return [];
    }

    if ($rtt_ns === 0) {
      $findings[] = new Finding(
        type: 'rtt_unknown',
        severity: Finding::SEVERITY_MEDIUM,
        title: 'No hay medición de latencia de red',
        detail: 'Sin una medición del RTT contra la base de datos no se puede separar el tiempo de red del tiempo de ejecución, y las estimaciones de ahorro quedan desactivadas. Ejecuta "drush db-audit:rtt" desde el mismo host que sirve la aplicación.',
      );
    }

    $findings = array_merge(
      $findings,
      $this->analyzeConnection($snapshot, $rtt_ms),
      $this->analyzeVolume($snapshot, $config->get('high_query_count') ?? 150, $rtt_ms),
      $this->analyzeNetworkShare($snapshot, $rtt_ns),
      $this->analyzeQueries($snapshot, $config, $rtt_ms),
      $this->analyzeInfrastructure($snapshot),
    );

    usort($findings, static function (Finding $a, Finding $b): int {
      return [$b->severityWeight(), $b->savingMs] <=> [$a->severityWeight(), $a->savingMs];
    });

    return $findings;
  }

  /**
   * Coste de establecer la conexión y atributos PDO efectivos.
   */
  protected function analyzeConnection(array $snapshot, float $rtt_ms): array {
    $findings = [];
    $info = $snapshot['connect_info'] ?? [];
    $connect_ms = ((int) ($snapshot['connect_ns'] ?? 0)) / 1e6;
    $connect_count = (int) ($snapshot['connect_count'] ?? 0);

    // Prepares no emulados: PDO hace un viaje para preparar y otro para
    // ejecutar. Con la base de datos en otra zona eso duplica literalmente el
    // coste de red de todas y cada una de las consultas del sitio.
    if (isset($info['emulate_prepares']) && $info['emulate_prepares'] === FALSE) {
      $total_queries = (int) ($snapshot['total_queries'] ?? 0);
      $findings[] = new Finding(
        type: 'emulate_prepares_off',
        severity: Finding::SEVERITY_CRITICAL,
        title: 'PDO::ATTR_EMULATE_PREPARES está desactivado',
        detail: sprintf(
          'Con los prepares no emulados cada consulta cuesta dos round-trips (PREPARE y EXECUTE) en lugar de uno. Con %d consultas en este request y un RTT de %.2f ms, eso son %.0f ms de red evitables. El driver de core lo deja activado por defecto: revisa el array "pdo" de $databases en settings.php.',
          $total_queries,
          $rtt_ms,
          $total_queries * $rtt_ms
        ),
        savingMs: $total_queries * $rtt_ms,
        data: ['queries' => $total_queries],
      );
    }

    if ($connect_count > 0 && empty($info['persistent'])) {
      // El handshake (TCP + TLS + autenticación) es lo que se ahorra con
      // conexiones persistentes. Los init_commands que ejecuta el driver de
      // core (SET NAMES y SET sql_mode, más el nivel de aislamiento si está
      // configurado) se siguen pagando en cada request, así que se descuentan
      // de la estimación.
      $init_ms = ((int) ($info['init_commands'] ?? 0)) * $rtt_ms;
      $saving = max(0.0, $connect_ms - $init_ms);

      if ($connect_ms >= 3.0) {
        $findings[] = new Finding(
          type: 'connection_overhead',
          severity: $connect_ms >= 10.0 ? Finding::SEVERITY_HIGH : Finding::SEVERITY_MEDIUM,
          title: sprintf('Abrir la conexión cuesta %.1f ms por request', $connect_ms),
          detail: sprintf(
            'Se abrieron %d conexión(es) sin persistencia, con un coste total de %.1f ms: handshake TCP, negociación TLS, autenticación y %d comando(s) de inicialización que el driver ejecuta siempre. Este coste se paga íntegro en cada request antes de la primera consulta útil. Evaluar PDO::ATTR_PERSISTENT en settings.php (con cuidado: las conexiones persistentes conservan estado de sesión entre requests) o un pooler de conexiones.',
            $connect_count,
            $connect_ms,
            (int) ($info['init_commands'] ?? 0)
          ),
          savingMs: $saving,
          data: ['connect_ms' => $connect_ms, 'connections' => $connect_count],
        );
      }
    }

    return $findings;
  }

  /**
   * Volumen bruto de consultas.
   */
  protected function analyzeVolume(array $snapshot, int $threshold, float $rtt_ms): array {
    $total = (int) ($snapshot['total_queries'] ?? 0);
    if ($total <= $threshold) {
      return [];
    }

    return [
      new Finding(
        type: 'high_query_count',
        severity: $total > $threshold * 3 ? Finding::SEVERITY_HIGH : Finding::SEVERITY_MEDIUM,
        title: sprintf('%d consultas en un solo request', $total),
        detail: sprintf(
          'El umbral configurado son %d. Cada consulta por encima de ese umbral cuesta como mínimo un RTT (%.2f ms) aunque el servidor la resuelva instantáneamente.',
          $threshold,
          $rtt_ms
        ),
        savingMs: ($total - $threshold) * $rtt_ms,
        data: ['queries' => $total, 'threshold' => $threshold],
      ),
    ];
  }

  /**
   * Qué proporción del tiempo en base de datos es pura latencia de red.
   *
   * Este es el hallazgo que responde a la pregunta de fondo: si el problema
   * está en el SQL o en la topología.
   */
  protected function analyzeNetworkShare(array $snapshot, int $rtt_ns): array {
    $total_queries = (int) ($snapshot['total_queries'] ?? 0);
    $total_db_ns = (int) ($snapshot['total_db_ns'] ?? 0);

    if ($rtt_ns === 0 || $total_db_ns === 0 || $total_queries < 20) {
      return [];
    }

    $network_ns = $total_queries * $rtt_ns;
    $share = $network_ns / $total_db_ns;

    if ($share < 0.5) {
      return [];
    }

    return [
      new Finding(
        type: 'network_bound',
        severity: $share >= 0.75 ? Finding::SEVERITY_CRITICAL : Finding::SEVERITY_HIGH,
        title: sprintf('El %.0f%% del tiempo en base de datos es latencia de red', $share * 100),
        detail: sprintf(
          'De %.0f ms totales en base de datos, unos %.0f ms son round-trips (%d consultas x %.2f ms de RTT) y sólo unos %.0f ms son ejecución real en el servidor. Optimizar SQL o añadir índices apenas moverá la aguja: lo que hay que reducir es el NÚMERO de consultas (agrupar en IN(), loadMultiple(), caché estática por request).',
          $total_db_ns / 1e6,
          $network_ns / 1e6,
          $total_queries,
          $rtt_ns / 1e6,
          max(0, $total_db_ns - $network_ns) / 1e6
        ),
        savingMs: 0.0,
        data: [
          'network_ms' => $network_ns / 1e6,
          'execution_ms' => max(0, $total_db_ns - $network_ns) / 1e6,
          'share' => $share,
        ],
      ),
    ];
  }

  /**
   * Hallazgos por consulta agregada: N+1, duplicados exactos, lentas y
   * resultados desmesurados.
   */
  protected function analyzeQueries(array $snapshot, $config, float $rtt_ms): array {
    $findings = [];
    $n_plus_one = (int) ($config->get('n_plus_one_threshold') ?? 10);
    $slow_ms = (float) ($config->get('slow_query_ms') ?? 50);
    $large_rows = (int) ($config->get('large_resultset') ?? 500);

    foreach ($snapshot['queries'] ?? [] as $agg) {
      $count = (int) $agg['count'];
      $time_ms = $agg['time_ns'] / 1e6;
      $caller = $agg['caller_file'] . ':' . $agg['caller_line'];

      // N+1: la misma consulta, desde el mismo punto del código, repetida con
      // argumentos distintos. Colapsarla en una sola consulta con IN() ahorra
      // count-1 round-trips.
      if ($count >= $n_plus_one) {
        $findings[] = new Finding(
          type: 'n_plus_one',
          severity: match (TRUE) {
            $count >= 100 => Finding::SEVERITY_CRITICAL,
            $count >= 30 => Finding::SEVERITY_HIGH,
            default => Finding::SEVERITY_MEDIUM,
          },
          title: sprintf('%d ejecuciones de la misma consulta desde %s', $count, $caller),
          detail: sprintf(
            'Patrón N+1: %s. Total %.1f ms. Si se agrupa en una única consulta se ahorran %d round-trips (%.1f ms con el RTT actual). Buscar un loadMultiple(), un IN() o una caché estática en el punto indicado.',
            $this->truncate($agg['sql'], 300),
            $time_ms,
            $count - 1,
            ($count - 1) * $rtt_ms
          ),
          savingMs: ($count - 1) * $rtt_ms,
          fingerprint: $agg['fingerprint'],
          module: $agg['module'],
          caller: $caller,
          data: ['count' => $count, 'time_ms' => $time_ms],
        );
      }

      // Ejecuciones idénticas (mismo SQL y mismos argumentos): el resultado ya
      // se conocía. Se elimina el 100% de su coste con una caché estática.
      if ((int) $agg['duplicates'] > 0) {
        $dups = (int) $agg['duplicates'];
        $avg_ms = $count > 0 ? $time_ms / $count : 0.0;
        $findings[] = new Finding(
          type: 'duplicate_query',
          severity: $dups >= 10 ? Finding::SEVERITY_HIGH : Finding::SEVERITY_MEDIUM,
          title: sprintf('%d ejecuciones idénticas repetidas desde %s', $dups, $caller),
          detail: sprintf(
            'La consulta se ejecutó %d vez/veces con exactamente los mismos argumentos: %s. El resultado ya se conocía, así que una caché estática en memoria elimina íntegramente su coste (%.1f ms).',
            $dups,
            $this->truncate($agg['sql'], 300),
            $dups * $avg_ms
          ),
          savingMs: $dups * $avg_ms,
          fingerprint: $agg['fingerprint'],
          module: $agg['module'],
          caller: $caller,
          data: ['duplicates' => $dups],
        );
      }

      // Consulta lenta de verdad: el tiempo que excede al RTT es ejecución en
      // el servidor, y ahí sí aplican índices y reescritura de SQL.
      $max_ms = $agg['max_ns'] / 1e6;
      if ($max_ms >= $slow_ms) {
        $execution_ms = max(0.0, $time_ms - ($count * $rtt_ms));
        $findings[] = new Finding(
          type: 'slow_query',
          severity: $max_ms >= $slow_ms * 4 ? Finding::SEVERITY_HIGH : Finding::SEVERITY_MEDIUM,
          title: sprintf('Consulta lenta: %.1f ms en el peor caso', $max_ms),
          detail: sprintf(
            '%s (desde %s). %d ejecución(es), %.1f ms en total, de los que ~%.1f ms son ejecución real en el servidor y no latencia. Este sí es un caso de EXPLAIN e índices.',
            $this->truncate($agg['sql'], 300),
            $caller,
            $count,
            $time_ms,
            $execution_ms
          ),
          savingMs: $execution_ms,
          fingerprint: $agg['fingerprint'],
          module: $agg['module'],
          caller: $caller,
          data: ['max_ms' => $max_ms, 'count' => $count],
        );
      }

      // Volumen de filas desproporcionado.
      $avg_rows = $count > 0 ? $agg['rows'] / $count : 0;
      if ($avg_rows >= $large_rows && $agg['operation'] === 'SELECT') {
        $findings[] = new Finding(
          type: 'large_resultset',
          severity: Finding::SEVERITY_MEDIUM,
          title: sprintf('Consulta que devuelve ~%d filas de media', (int) $avg_rows),
          detail: sprintf(
            '%s (desde %s). Mover %d filas entre zonas cuesta ancho de banda y memoria en PHP. Comprobar si hace falta ese volumen o si falta un LIMIT o una paginación.',
            $this->truncate($agg['sql'], 300),
            $caller,
            (int) $avg_rows
          ),
          fingerprint: $agg['fingerprint'],
          module: $agg['module'],
          caller: $caller,
          data: ['avg_rows' => $avg_rows],
        );
      }

      // SELECT * : trae columnas que nadie usa, y en cross-AZ eso es ancho de
      // banda pagado por nada.
      if ($agg['operation'] === 'SELECT' && preg_match('/SELECT\s+\*/i', $agg['sql'])) {
        $findings[] = new Finding(
          type: 'select_star',
          severity: Finding::SEVERITY_LOW,
          title: sprintf('SELECT * desde %s', $caller),
          detail: sprintf('%s. Seleccionar sólo las columnas necesarias reduce el volumen transferido entre zonas.', $this->truncate($agg['sql'], 300)),
          fingerprint: $agg['fingerprint'],
          module: $agg['module'],
          caller: $caller,
        );
      }
    }

    return $findings;
  }

  /**
   * Tráfico contra tablas que deberían estar servidas desde otro sitio.
   */
  protected function analyzeInfrastructure(array $snapshot): array {
    $findings = [];
    $cache_queries = 0;
    $cache_ns = 0;
    $cache_tables = [];
    $infra = [];

    foreach ($snapshot['queries'] ?? [] as $agg) {
      foreach (explode(',', (string) $agg['tables']) as $table) {
        $table = trim($table);
        if ($table === '') {
          continue;
        }

        if (str_starts_with($table, 'cache_') || $table === 'cachetags') {
          $cache_queries += (int) $agg['count'];
          $cache_ns += (int) $agg['time_ns'];
          $cache_tables[$table] = TRUE;
        }

        if (isset(self::INFRA_TABLES[$table])) {
          $infra[$table]['count'] = ($infra[$table]['count'] ?? 0) + (int) $agg['count'];
          $infra[$table]['ns'] = ($infra[$table]['ns'] ?? 0) + (int) $agg['time_ns'];
        }
      }
    }

    if ($cache_queries > 0) {
      $findings[] = new Finding(
        type: 'cache_in_database',
        severity: $cache_queries >= 20 ? Finding::SEVERITY_HIGH : Finding::SEVERITY_MEDIUM,
        title: sprintf('%d consultas a tablas de caché en la base de datos', $cache_queries),
        detail: sprintf(
          'Tablas implicadas: %s. Con Redis configurado, este tráfico no debería existir: indica bins de caché que no están yendo a Redis y que por tanto pagan el salto entre zonas. Revisar cache.bins, el default_bin_backend y el bin de cache_tags en settings.php. Coste actual: %.1f ms.',
          implode(', ', array_keys($cache_tables)),
          $cache_ns / 1e6
        ),
        savingMs: $cache_ns / 1e6,
        data: ['queries' => $cache_queries, 'tables' => array_keys($cache_tables)],
      );
    }

    foreach ($infra as $table => $stats) {
      if ($stats['count'] < 5) {
        continue;
      }
      $findings[] = new Finding(
        type: 'hot_infrastructure_table',
        severity: Finding::SEVERITY_MEDIUM,
        title: sprintf('%d consultas a la tabla "%s" (%s)', $stats['count'], $table, self::INFRA_TABLES[$table]),
        detail: sprintf(
          'Tráfico de infraestructura contra la base de datos, %.1f ms en este request. Estas tablas suelen poder moverse fuera de la BD o reducirse: sesiones a Redis, dblog a un backend externo, y flood/semáforos revisando su uso.',
          $stats['ns'] / 1e6
        ),
        savingMs: $stats['ns'] / 1e6,
        data: ['table' => $table, 'count' => $stats['count']],
      );
    }

    return $findings;
  }

  /**
   * Recorta texto largo para los mensajes.
   */
  protected function truncate(string $text, int $length): string {
    return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
  }

}
