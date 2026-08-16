<?php

declare(strict_types=1);

namespace Drupal\db_audit\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\State\StateInterface;
use Drupal\db_audit\Diagnostics\ConnectionDiagnostics;
use Drupal\db_audit\Storage\AuditStorage;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Comandos de la auditoría de base de datos.
 *
 * La medición de latencia se hace desde aquí a propósito: hay que ejecutarla
 * desde el mismo host que sirve la aplicación para que el número refleje lo
 * que pagan los requests reales.
 */
class DbAuditCommands extends DrushCommands implements ContainerInjectionInterface {

  public function __construct(
    protected readonly ConnectionDiagnostics $diagnostics,
    protected readonly AuditStorage $storage,
    protected readonly StateInterface $state,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('db_audit.diagnostics'),
      $container->get('db_audit.storage'),
      $container->get('state'),
    );
  }

  /**
   * Mide la latencia contra la base de datos y el coste de abrir conexión.
   */
  #[CLI\Command(name: 'db-audit:rtt', aliases: ['dba-rtt'])]
  #[CLI\Option(name: 'samples', description: 'Número de mediciones de SELECT 1.')]
  #[CLI\Option(name: 'no-persist', description: 'No guardar el resultado como RTT de referencia.')]
  #[CLI\Usage(name: 'drush db-audit:rtt --samples=100', description: 'Mide con 100 muestras.')]
  public function rtt(array $options = ['samples' => 50, 'no-persist' => FALSE]): void {
    $stats = $this->diagnostics->measureRtt((int) $options['samples'], !$options['no-persist']);

    $this->io()->section('Latencia por consulta (SELECT 1)');
    $this->io()->definitionList(
      ['Mediana (RTT de referencia)' => sprintf('%.3f ms', $stats['median_ns'] / 1e6)],
      ['Mínimo (suelo de la red)' => sprintf('%.3f ms', $stats['min_ns'] / 1e6)],
      ['Percentil 95' => sprintf('%.3f ms', $stats['p95_ns'] / 1e6)],
      ['Máximo' => sprintf('%.3f ms', $stats['max_ns'] / 1e6)],
      ['Muestras' => $stats['samples']],
    );

    $connect = $this->diagnostics->measureConnect(5);
    if (isset($connect['error'])) {
      $this->io()->warning($connect['error']);
      return;
    }

    $this->io()->section('Coste de abrir una conexión nueva');
    $this->io()->definitionList(
      ['Handshake (TCP + TLS + auth)' => sprintf('%.2f ms', $connect['handshake_median_ns'] / 1e6)],
      [sprintf('Comandos de inicialización (%d)', $connect['init_command_count']) => sprintf('%.2f ms', $connect['init_median_ns'] / 1e6)],
      ['Total por conexión' => sprintf('%.2f ms', $connect['total_median_ns'] / 1e6)],
    );

    $rtt_ms = $stats['median_ns'] / 1e6;
    $this->io()->note(sprintf(
      'Con este RTT, un request de 300 consultas paga %.0f ms sólo en idas y vueltas, resuelva el servidor lo que resuelva.',
      300 * $rtt_ms
    ));
  }

  /**
   * Muestra el estado efectivo de la conexión a la base de datos.
   */
  #[CLI\Command(name: 'db-audit:diagnose', aliases: ['dba-diag'])]
  public function diagnose(): void {
    $info = $this->diagnostics->connectionInfo();

    $this->io()->definitionList(
      ['Driver' => $info['driver'] . ($info['driver'] === 'auditmysql' ? ' (instrumentado)' : ' (SIN instrumentar: no se captura nada)')],
      ['Host' => (string) $info['host']],
      ['Base de datos' => (string) $info['database']],
      ['Versión del servidor' => (string) ($info['server_version'] ?? '?')],
      ['TLS' => !empty($info['tls']) ? 'sí (' . $info['tls_cipher'] . ')' : 'no'],
      ['Conexiones persistentes' => !empty($info['persistent']) ? 'sí' : 'no'],
      ['Prepares emulados' => !empty($info['emulate_prepares']) ? 'sí (1 round-trip por consulta)' : 'NO (2 round-trips por consulta)'],
      ['Comandos de inicialización' => (string) $info['init_commands']],
      ['Réplica configurada' => !empty($info['has_replica']) ? 'sí' : 'no'],
      ['Almacenamiento aislado' => !empty($info['audit_storage_isolated']) ? 'sí (conexión dbaudit)' : 'no (misma base auditada)'],
      ['Conexiones abiertas en el servidor' => (string) ($info['threads_connected'] ?? '?')],
    );

    if (empty($info['emulate_prepares'])) {
      $this->io()->error('PDO::ATTR_EMULATE_PREPARES está desactivado: cada consulta cuesta el doble de red. Revisa el array "pdo" de $databases en settings.php.');
    }
    if (empty($info['persistent'])) {
      $this->io()->warning('Sin conexiones persistentes: cada request paga el handshake completo. Ejecuta db-audit:rtt para ver cuánto cuesta.');
    }
  }

  /**
   * Crea las tablas de auditoría en la conexión de almacenamiento.
   *
   * Necesario cuando la conexión 'dbaudit' se declara DESPUÉS de instalar el
   * módulo, que es el orden natural en un despliegue: primero sube la imagen
   * con el código, luego se ajusta la configuración del entorno. El módulo las
   * crearía igualmente al primer volcado, pero en un despliegue conviene
   * hacerlo de forma explícita y comprobar el resultado.
   */
  #[CLI\Command(name: 'db-audit:install-schema', aliases: ['dba-schema'])]
  public function installSchema(): void {
    $isolated = AuditStorage::isolated();
    AuditStorage::ensureSchema();

    $connection = AuditStorage::connection();
    $missing = [];
    foreach (['db_audit_request', 'db_audit_query', 'db_audit_finding'] as $table) {
      if (!$connection->schema()->tableExists($table)) {
        $missing[] = $table;
      }
    }

    if ($missing !== []) {
      $this->io()->error(sprintf(
        'No se pudieron crear estas tablas: %s. Revisa que el usuario de la conexión tenga permiso CREATE sobre la base de datos.',
        implode(', ', $missing)
      ));
      return;
    }

    $this->io()->success(sprintf(
      'Tablas de auditoría listas en %s.',
      $isolated ? "la conexión separada 'dbaudit'" : 'la base de datos auditada'
    ));

    if (!$isolated) {
      $this->io()->warning('No hay conexión "dbaudit" declarada: los resultados se guardarán en la misma base que se está auditando.');
    }
  }

  /**
   * Resumen de lo capturado.
   */
  #[CLI\Command(name: 'db-audit:summary', aliases: ['dba-sum'])]
  #[CLI\Option(name: 'tag', description: 'Filtrar por etiqueta de corrida.')]
  public function summary(array $options = ['tag' => NULL]): void {
    $summary = $this->storage->summary($options['tag']);
    $requests = (int) ($summary['requests'] ?? 0);

    if ($requests === 0) {
      $this->io()->warning('No hay datos capturados.');
      return;
    }

    $avg_db = (float) ($summary['avg_db_ms'] ?? 0);
    $avg_net = (float) ($summary['avg_network_ms'] ?? 0);

    $this->io()->definitionList(
      ['Requests auditados' => $requests],
      ['Consultas totales' => (int) ($summary['queries'] ?? 0)],
      ['Consultas por request (media)' => sprintf('%.1f', (float) ($summary['avg_queries'] ?? 0))],
      ['Tiempo en BD por request (media)' => sprintf('%.1f ms', $avg_db)],
      ['De ello, red (estimado)' => sprintf('%.1f ms (%.0f%%)', $avg_net, $avg_db > 0 ? min(100, $avg_net / $avg_db * 100) : 0)],
      ['Apertura de conexión (media)' => sprintf('%.1f ms', (float) ($summary['avg_connect_ms'] ?? 0))],
      ['Margen estimado por request' => sprintf('%.1f ms', $requests > 0 ? ((float) ($summary['total_saving'] ?? 0)) / $requests : 0)],
    );
  }

  /**
   * Rutas ordenadas por consultas por request.
   */
  #[CLI\Command(name: 'db-audit:routes', aliases: ['dba-routes'])]
  #[CLI\Option(name: 'tag', description: 'Filtrar por etiqueta de corrida.')]
  #[CLI\Option(name: 'limit', description: 'Número de filas.')]
  #[CLI\FieldLabels(labels: [
    'route' => 'Ruta',
    'samples' => 'Muestras',
    'avg_queries' => 'Consultas/req',
    'max_queries' => 'Máx',
    'avg_db_ms' => 'BD ms',
    'net_pct' => '% red',
    'avg_saving_ms' => 'Margen ms',
  ])]
  public function routes(array $options = ['tag' => NULL, 'limit' => 50, 'format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->storage->topRoutes((int) $options['limit'], $options['tag']) as $route) {
      $avg_db = (float) $route->avg_db_ms;
      $avg_net = (float) $route->avg_network_ms;
      $rows[] = [
        'route' => $route->route !== '' ? $route->route : '(sin ruta)',
        'samples' => (int) $route->samples,
        'avg_queries' => sprintf('%.1f', (float) $route->avg_queries),
        'max_queries' => (int) $route->max_queries,
        'avg_db_ms' => sprintf('%.1f', $avg_db),
        'net_pct' => $avg_db > 0 ? sprintf('%.0f%%', min(100, $avg_net / $avg_db * 100)) : '—',
        'avg_saving_ms' => sprintf('%.1f', (float) $route->avg_saving_ms),
      ];
    }

    return new RowsOfFields($rows);
  }

  /**
   * Hallazgos ordenados por milisegundos recuperables.
   */
  #[CLI\Command(name: 'db-audit:findings', aliases: ['dba-find'])]
  #[CLI\Option(name: 'tag', description: 'Filtrar por etiqueta de corrida.')]
  #[CLI\Option(name: 'route', description: 'Filtrar por ruta.')]
  #[CLI\Option(name: 'limit', description: 'Número de filas.')]
  #[CLI\FieldLabels(labels: [
    'severity' => 'Sev',
    'type' => 'Tipo',
    'module' => 'Módulo',
    'occurrences' => 'Veces',
    'avg_saving' => 'ms/req',
    'total_saving' => 'Total ms',
    'title' => 'Hallazgo',
  ])]
  public function findings(array $options = ['tag' => NULL, 'route' => NULL, 'limit' => 50, 'format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->storage->topFindings((int) $options['limit'], $options['tag'], $options['route']) as $finding) {
      $rows[] = [
        'severity' => $finding->severity,
        'type' => $finding->type,
        'module' => $finding->module !== '' ? $finding->module : '—',
        'occurrences' => (int) $finding->occurrences,
        'avg_saving' => sprintf('%.1f', (float) $finding->avg_saving),
        'total_saving' => sprintf('%.1f', (float) $finding->total_saving),
        'title' => $finding->title,
      ];
    }

    return new RowsOfFields($rows);
  }

  /**
   * Consultas más ejecutadas.
   */
  #[CLI\Command(name: 'db-audit:queries', aliases: ['dba-q'])]
  #[CLI\Option(name: 'tag', description: 'Filtrar por etiqueta de corrida.')]
  #[CLI\Option(name: 'route', description: 'Filtrar por ruta.')]
  #[CLI\Option(name: 'module', description: 'Filtrar por módulo responsable.')]
  #[CLI\Option(name: 'limit', description: 'Número de filas.')]
  #[CLI\FieldLabels(labels: [
    'total_exec' => 'Ejec',
    'avg_exec' => 'Por req',
    'total_ms' => 'Total ms',
    'worst_ms' => 'Peor ms',
    'module' => 'Módulo',
    'caller' => 'Origen',
    'sql' => 'SQL',
  ])]
  public function queries(array $options = ['tag' => NULL, 'route' => NULL, 'module' => NULL, 'limit' => 30, 'format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->storage->topQueries((int) $options['limit'], $options['tag'], $options['route'], $options['module']) as $query) {
      $rows[] = [
        'total_exec' => (int) $query->total_exec,
        'avg_exec' => sprintf('%.1f', (float) $query->avg_exec_per_request),
        'total_ms' => sprintf('%.1f', (float) $query->total_ms),
        'worst_ms' => sprintf('%.1f', (float) $query->worst_ms),
        'module' => $query->module,
        'caller' => $query->caller_file . ':' . $query->caller_line,
        'sql' => mb_substr((string) $query->sql, 0, 120),
      ];
    }

    return new RowsOfFields($rows);
  }

  /**
   * Reparto de consultas por módulo responsable.
   */
  #[CLI\Command(name: 'db-audit:modules', aliases: ['dba-mod'])]
  #[CLI\Option(name: 'tag', description: 'Filtrar por etiqueta de corrida.')]
  #[CLI\Option(name: 'limit', description: 'Número de filas.')]
  #[CLI\FieldLabels(labels: [
    'module' => 'Módulo',
    'total_exec' => 'Ejecuciones',
    'per_request' => 'Por request',
    'distinct_queries' => 'Distintas',
    'total_ms' => 'Total ms',
  ])]
  public function modules(array $options = ['tag' => NULL, 'limit' => 40, 'format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->storage->byModule((int) $options['limit'], $options['tag']) as $module) {
      $requests = max(1, (int) $module->requests);
      $rows[] = [
        'module' => $module->module,
        'total_exec' => (int) $module->total_exec,
        'per_request' => sprintf('%.1f', (float) $module->total_exec / $requests),
        'distinct_queries' => (int) $module->distinct_queries,
        'total_ms' => sprintf('%.1f', (float) $module->total_ms),
      ];
    }

    return new RowsOfFields($rows);
  }

  /**
   * Fija la etiqueta con la que se marcará lo que se capture a partir de ahora.
   */
  #[CLI\Command(name: 'db-audit:tag', aliases: ['dba-tag'])]
  #[CLI\Argument(name: 'tag', description: 'Etiqueta, o vacío para quitarla.')]
  #[CLI\Usage(name: 'drush db-audit:tag base', description: 'Marca la corrida de referencia antes de aplicar cambios.')]
  public function tag(string $tag = ''): void {
    $tag = trim($tag);
    if ($tag === '') {
      $this->state->delete('db_audit.run_tag');
      $this->io()->success('Etiqueta eliminada.');
      return;
    }

    $this->state->set('db_audit.run_tag', mb_substr($tag, 0, 64));
    $this->io()->success(sprintf('Las capturas a partir de ahora se marcan como "%s".', $tag));
  }

  /**
   * Borra datos capturados.
   */
  #[CLI\Command(name: 'db-audit:purge', aliases: ['dba-purge'])]
  #[CLI\Option(name: 'tag', description: 'Borrar sólo una corrida.')]
  #[CLI\Option(name: 'days', description: 'Borrar lo anterior a N días.')]
  public function purge(array $options = ['tag' => NULL, 'days' => NULL]): void {
    $before = NULL;
    if ($options['days'] !== NULL) {
      $before = time() - ((int) $options['days'] * 86400);
    }

    if ($before === NULL && empty($options['tag'])
      && !$this->io()->confirm('Se van a borrar TODOS los datos de auditoría. ¿Continuar?', FALSE)) {
      return;
    }

    $this->storage->purge($before, $options['tag']);
    $this->io()->success('Datos purgados.');
  }

}
