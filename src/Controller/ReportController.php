<?php

declare(strict_types=1);

namespace Drupal\db_audit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\db_audit\Diagnostics\ConnectionDiagnostics;
use Drupal\db_audit\Storage\AuditStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Informes de la auditoría de base de datos.
 */
class ReportController extends ControllerBase {

  public function __construct(
    protected readonly AuditStorage $storage,
    protected readonly ConnectionDiagnostics $diagnostics,
    protected readonly StateInterface $auditState,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('db_audit.storage'),
      $container->get('db_audit.diagnostics'),
      $container->get('state'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
    );
  }

  /**
   * Resumen general.
   */
  public function overview(): array {
    $tag = $this->currentTag();
    $summary = $this->storage->summary($tag);
    $rtt_ns = (int) $this->auditState->get('db_audit.rtt_ns', 0);

    $build = [];
    $build['filter'] = $this->tagFilter($tag);
    $build['intro'] = $this->intro($rtt_ns);

    $requests = (int) ($summary['requests'] ?? 0);
    if ($requests === 0) {
      $build['empty'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Todavía no hay datos capturados. Comprueba que settings.php apunta al driver instrumentado y que la captura está activada.') . '</p>',
      ];
      return $build;
    }

    $avg_queries = (float) ($summary['avg_queries'] ?? 0);
    $avg_db = (float) ($summary['avg_db_ms'] ?? 0);
    $avg_net = (float) ($summary['avg_network_ms'] ?? 0);
    $avg_connect = (float) ($summary['avg_connect_ms'] ?? 0);

    $build['summary'] = [
      '#type' => 'table',
      '#caption' => $this->t('Resumen de @n requests auditados', ['@n' => $requests]),
      '#header' => [$this->t('Métrica'), $this->t('Valor'), $this->t('Lectura')],
      '#rows' => [
        [
          $this->t('Consultas por request (media)'),
          number_format($avg_queries, 1),
          $this->t('Cada consulta cuesta como mínimo un round-trip, resuélvala el servidor en el tiempo que sea.'),
        ],
        [
          $this->t('Tiempo en base de datos por request (media)'),
          $this->t('@ms ms', ['@ms' => number_format($avg_db, 1)]),
          $this->t('Suma de todas las consultas, latencia incluida.'),
        ],
        [
          $this->t('De ello, latencia de red (estimado)'),
          $this->t('@ms ms', ['@ms' => number_format($avg_net, 1)]),
          $avg_db > 0
            ? $this->t('El @pct% del tiempo en base de datos es ida y vuelta, no trabajo del servidor.', ['@pct' => number_format(min(100, $avg_net / $avg_db * 100), 0)])
            : $this->t('Sin datos.'),
        ],
        [
          $this->t('Apertura de conexión por request (media)'),
          $this->t('@ms ms', ['@ms' => number_format($avg_connect, 1)]),
          $this->t('Handshake TCP, TLS, autenticación y comandos de inicialización.'),
        ],
        [
          $this->t('Margen estimado de mejora por request'),
          $this->t('@ms ms', ['@ms' => number_format($requests > 0 ? ((float) ($summary['total_saving'] ?? 0)) / $requests : 0, 1)]),
          Link::createFromRoute($this->t('Ver los hallazgos que lo componen'), 'db_audit.findings', [], ['query' => $tag ? ['tag' => $tag] : []])->toString(),
        ],
      ],
    ];

    $build['distribution'] = $this->distributionTable($tag);
    $build['routes'] = $this->routesTable($tag);

    return $build;
  }

  /**
   * Distribución de consultas por request.
   */
  protected function distributionTable(?string $tag): array {
    $dist = $this->storage->queryDistribution($tag);
    if ($dist === []) {
      return [];
    }

    $rows = [
      [$this->t('Mínimo'), (int) $dist['min']],
      [$this->t('Mediana'), (int) $dist['median']],
      [$this->t('Percentil 95'), (int) $dist['p95']],
      [$this->t('Máximo'), (int) $dist['max']],
      [
        $this->t('Requests con menos de 20 consultas'),
        $this->t('@pct%', ['@pct' => number_format($dist['light_share'] * 100, 0)]),
      ],
    ];

    $build = [
      '#type' => 'table',
      '#caption' => $this->t('Distribución de consultas por request'),
      '#header' => [$this->t('Estadístico'), $this->t('Consultas')],
      '#rows' => $rows,
    ];

    // Con una caché externa delante, la media deja de representar a nadie.
    if ($dist['light_share'] >= 0.5 && $dist['max'] >= $dist['median'] * 5) {
      $build['#footer'] = [
        [
          [
            'data' => $this->t('La distribución es bimodal: el @pct% de los requests apenas toca la base de datos (caché externa funcionando) y unos pocos disparan hasta @max consultas. Guíate por la mediana y por el máximo de cada ruta, no por la media.', [
              '@pct' => number_format($dist['light_share'] * 100, 0),
              '@max' => (int) $dist['max'],
            ]),
            'colspan' => 2,
          ],
        ],
      ];
    }

    return $build;
  }

  /**
   * Tabla de rutas ordenadas por consultas.
   */
  protected function routesTable(?string $tag): array {
    $rows = [];
    foreach ($this->storage->topRoutes(50, $tag) as $route) {
      $avg_db = (float) $route->avg_db_ms;
      $avg_net = (float) $route->avg_network_ms;
      $rows[] = [
        $route->route !== '' ? $route->route : $this->t('(sin ruta)'),
        (int) $route->samples,
        number_format((float) $route->avg_queries, 1),
        (int) $route->max_queries,
        number_format($avg_db, 1),
        $avg_db > 0 ? number_format(min(100, $avg_net / $avg_db * 100), 0) . '%' : '—',
        number_format((float) $route->avg_saving_ms, 1),
      ];
    }

    return [
      '#type' => 'table',
      '#caption' => $this->t('Rutas ordenadas por consultas por request'),
      '#header' => [
        $this->t('Ruta'),
        $this->t('Muestras'),
        $this->t('Consultas (media)'),
        $this->t('Consultas (máx.)'),
        $this->t('BD ms (media)'),
        $this->t('% red'),
        $this->t('Margen ms'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Sin datos.'),
    ];
  }

  /**
   * Hallazgos priorizados.
   */
  public function findings(): array {
    $tag = $this->currentTag();
    $build = [];
    $build['filter'] = $this->tagFilter($tag);

    $rows = [];
    foreach ($this->storage->topFindings(200, $tag) as $finding) {
      $rows[] = [
        ['data' => $this->severityLabel($finding->severity), 'class' => ['db-audit-severity-' . $finding->severity]],
        $finding->type,
        ['data' => ['#plain_text' => $finding->title]],
        $finding->module !== '' ? $finding->module : '—',
        (int) $finding->occurrences,
        number_format((float) $finding->avg_saving, 1),
        number_format((float) $finding->total_saving, 1),
        // El detalle completo puede ocupar un párrafo largo y aquí hay una fila
        // por hallazgo: se recorta para que la tabla siga siendo legible. El
        // texto íntegro está en el detalle del request.
        ['data' => ['#plain_text' => $this->truncate((string) $finding->detail, 220)]],
      ];
    }

    $build['findings'] = [
      '#type' => 'table',
      '#caption' => $this->t('Hallazgos ordenados por severidad y, dentro de cada nivel, por impacto acumulado'),
      '#header' => [
        $this->t('Severidad'),
        $this->t('Tipo'),
        $this->t('Hallazgo'),
        $this->t('Módulo'),
        $this->t('Veces'),
        $this->t('Margen ms/req'),
        $this->t('Impacto total ms'),
        $this->t('Detalle'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Sin hallazgos.'),
    ];

    return $build;
  }

  /**
   * Consultas más repetidas.
   */
  public function queries(): array {
    $tag = $this->currentTag();
    $request = $this->requestStack->getCurrentRequest();
    $module = $request ? (string) $request->query->get('module', '') : '';
    $route = $request ? (string) $request->query->get('route', '') : '';

    $build = [];
    $build['filter'] = $this->tagFilter($tag);

    $rows = [];
    foreach ($this->storage->topQueries(200, $tag, $route ?: NULL, $module ?: NULL) as $query) {
      $rows[] = [
        (int) $query->total_exec,
        number_format((float) $query->avg_exec_per_request, 1),
        (int) $query->requests,
        number_format((float) $query->total_ms, 1),
        number_format((float) $query->worst_ms, 1),
        (int) $query->duplicates,
        $query->module,
        ['data' => ['#plain_text' => $query->caller_file . ':' . $query->caller_line]],
        ['data' => ['#plain_text' => $query->sql]],
      ];
    }

    $build['queries'] = [
      '#type' => 'table',
      '#caption' => $this->t('Consultas agrupadas por forma normalizada y punto de llamada'),
      '#header' => [
        $this->t('Ejecuciones'),
        $this->t('Por request'),
        $this->t('Requests'),
        $this->t('Total ms'),
        $this->t('Peor ms'),
        $this->t('Idénticas'),
        $this->t('Módulo'),
        $this->t('Origen'),
        $this->t('SQL normalizado'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Sin datos.'),
    ];

    return $build;
  }

  /**
   * Reparto por módulo responsable.
   */
  public function modules(): array {
    $tag = $this->currentTag();
    $build = [];
    $build['filter'] = $this->tagFilter($tag);

    $rows = [];
    foreach ($this->storage->byModule(100, $tag) as $module) {
      $requests = max(1, (int) $module->requests);
      $rows[] = [
        $module->module,
        (int) $module->total_exec,
        number_format((float) $module->total_exec / $requests, 1),
        (int) $module->distinct_queries,
        number_format((float) $module->total_ms, 1),
        Link::createFromRoute(
          $this->t('Ver consultas'),
          'db_audit.queries',
          [],
          ['query' => array_filter(['module' => $module->module, 'tag' => $tag])]
        ),
      ];
    }

    $build['modules'] = [
      '#type' => 'table',
      '#caption' => $this->t('Consultas atribuidas a cada módulo, tema o paquete'),
      '#header' => [
        $this->t('Módulo'),
        $this->t('Ejecuciones'),
        $this->t('Por request'),
        $this->t('Consultas distintas'),
        $this->t('Total ms'),
        $this->t('Acciones'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Sin datos.'),
    ];

    return $build;
  }

  /**
   * Requests recientes.
   */
  public function requests(): array {
    $tag = $this->currentTag();
    $build = [];
    $build['filter'] = $this->tagFilter($tag);

    $rows = [];
    foreach ($this->storage->recentRequests(100, $tag) as $request) {
      $rows[] = [
        $this->dateFormatter->format((int) $request->created, 'short'),
        $request->route !== '' ? $request->route : ($request->sapi === 'cli' ? $this->t('(CLI)') : $this->t('(sin ruta)')),
        ['data' => ['#plain_text' => $request->path]],
        $request->method,
        (int) $request->status,
        (int) $request->total_queries,
        number_format((float) $request->total_db_ms, 1),
        number_format((float) $request->connect_ms, 1),
        number_format((float) $request->saving_ms, 1),
        Link::createFromRoute($this->t('Detalle'), 'db_audit.request_detail', ['request_id' => $request->request_id]),
      ];
    }

    $build['requests'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Fecha'),
        $this->t('Ruta'),
        $this->t('Path'),
        $this->t('Método'),
        $this->t('Estado'),
        $this->t('Consultas'),
        $this->t('BD ms'),
        $this->t('Conexión ms'),
        $this->t('Margen ms'),
        '',
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Sin datos.'),
    ];

    return $build;
  }

  /**
   * Detalle de un request concreto.
   */
  public function requestDetail(string $request_id): array {
    $detail = $this->storage->requestDetail($request_id);
    if ($detail === []) {
      throw new NotFoundHttpException();
    }

    $request = $detail['request'];
    $build = [];

    $build['header'] = [
      '#type' => 'table',
      '#header' => [$this->t('Métrica'), $this->t('Valor')],
      '#rows' => [
        [$this->t('Ruta'), $request['route'] ?: '—'],
        [$this->t('Path'), ['data' => ['#plain_text' => $request['path']]]],
        [$this->t('Fecha'), $this->dateFormatter->format((int) $request['created'], 'long')],
        [$this->t('Host'), ['data' => ['#plain_text' => $request['hostname']]]],
        [$this->t('Consultas'), (int) $request['total_queries']],
        [$this->t('Consultas distintas'), (int) $request['distinct_queries']],
        [$this->t('Tiempo en BD'), $this->t('@ms ms', ['@ms' => number_format((float) $request['total_db_ms'], 2)])],
        [$this->t('De ello, red (estimado)'), $this->t('@ms ms', ['@ms' => number_format((float) $request['network_ms'], 2)])],
        [$this->t('Apertura de conexión'), $this->t('@ms ms (@n conexiones)', [
          '@ms' => number_format((float) $request['connect_ms'], 2),
          '@n' => (int) $request['connect_count'],
        ])],
        [$this->t('Margen estimado'), $this->t('@ms ms', ['@ms' => number_format((float) $request['saving_ms'], 2)])],
        [$this->t('Memoria pico'), ByteSizeMarkup::create((int) $request['peak_memory'])],
      ],
    ];

    $finding_rows = [];
    foreach ($detail['findings'] as $finding) {
      $finding_rows[] = [
        $this->severityLabel($finding->severity),
        $finding->type,
        ['data' => ['#plain_text' => $finding->title]],
        number_format((float) $finding->saving_ms, 1),
        ['data' => ['#plain_text' => $finding->detail]],
      ];
    }
    $build['findings'] = [
      '#type' => 'table',
      '#caption' => $this->t('Hallazgos de este request'),
      '#header' => [$this->t('Severidad'), $this->t('Tipo'), $this->t('Hallazgo'), $this->t('Margen ms'), $this->t('Detalle')],
      '#rows' => $finding_rows,
      '#empty' => $this->t('Sin hallazgos.'),
    ];

    $query_rows = [];
    foreach ($detail['queries'] as $query) {
      $query_rows[] = [
        (int) $query->exec_count,
        number_format((float) $query->time_ms, 2),
        number_format((float) $query->max_ms, 2),
        (int) $query->duplicates,
        (int) $query->rows_total,
        $query->operation,
        $query->module,
        ['data' => ['#plain_text' => $query->caller_file . ':' . $query->caller_line]],
        ['data' => ['#plain_text' => $query->sql]],
      ];
    }
    $build['queries'] = [
      '#type' => 'table',
      '#caption' => $this->t('Consultas de este request'),
      '#header' => [
        $this->t('Ejec.'),
        $this->t('ms'),
        $this->t('Peor ms'),
        $this->t('Idénticas'),
        $this->t('Filas'),
        $this->t('Op'),
        $this->t('Módulo'),
        $this->t('Origen'),
        $this->t('SQL'),
      ],
      '#rows' => $query_rows,
      '#empty' => $this->t('Sin consultas.'),
    ];

    return $build;
  }

  /**
   * Estado de la conexión a la base de datos.
   */
  public function diagnostics(): array {
    $info = $this->diagnostics->connectionInfo();
    $rtt = $this->auditState->get('db_audit.rtt_samples', []);
    $measured = (int) $this->auditState->get('db_audit.rtt_measured', 0);

    $rows = [
      [$this->t('Driver'), $info['driver'], $info['driver'] === 'auditmysql'
        ? $this->t('Instrumentado: la captura está activa.')
        : $this->t('No instrumentado: no se está capturando nada.')],
      [$this->t('Host'), ['data' => ['#plain_text' => (string) $info['host']]], ''],
      [$this->t('Versión del servidor'), $info['server_version'] ?? '—', ''],
      [$this->t('TLS'), !empty($info['tls']) ? $this->t('Sí (@c)', ['@c' => $info['tls_cipher']]) : $this->t('No'),
        !empty($info['tls'])
          ? $this->t('El handshake TLS añade viajes adicionales al abrir cada conexión.')
          : $this->t('Sin cifrado en tránsito.')],
      [$this->t('Conexiones persistentes'), !empty($info['persistent']) ? $this->t('Sí') : $this->t('No'),
        !empty($info['persistent'])
          ? $this->t('Se ahorra el handshake entre requests.')
          : $this->t('Cada request paga handshake completo. Evaluar PDO::ATTR_PERSISTENT o un pooler.')],
      [$this->t('Prepares emulados'), !empty($info['emulate_prepares']) ? $this->t('Sí') : $this->t('No'),
        !empty($info['emulate_prepares'])
          ? $this->t('Correcto: un round-trip por consulta.')
          : $this->t('Cada consulta cuesta dos round-trips. Revisar el array "pdo" en settings.php.')],
      [$this->t('Comandos de inicialización'), (int) $info['init_commands'],
        $this->t('Se ejecutan al abrir cada conexión, uno por round-trip.')],
      [$this->t('Réplica configurada'), !empty($info['has_replica']) ? $this->t('Sí') : $this->t('No'), ''],
      [$this->t('Almacenamiento de auditoría aislado'), !empty($info['audit_storage_isolated']) ? $this->t('Sí') : $this->t('No'),
        !empty($info['audit_storage_isolated'])
          ? $this->t('Los resultados van a una conexión separada y no contaminan la medición.')
          : $this->t('Los resultados se guardan en la misma base auditada. El volcado es una sola sentencia por tabla y se excluye de las métricas, pero considera declarar una conexión "dbaudit".')],
    ];

    $build['connection'] = [
      '#type' => 'table',
      '#caption' => $this->t('Estado efectivo de la conexión'),
      '#header' => [$this->t('Parámetro'), $this->t('Valor'), $this->t('Lectura')],
      '#rows' => $rows,
    ];

    if (!empty($rtt)) {
      $build['rtt'] = [
        '#type' => 'table',
        '#caption' => $this->t('Latencia medida el @date', [
          '@date' => $measured > 0 ? $this->dateFormatter->format($measured, 'short') : '—',
        ]),
        '#header' => [$this->t('Estadístico'), $this->t('Milisegundos')],
        '#rows' => [
          [$this->t('Mediana (usada como RTT)'), number_format(((int) $rtt['median_ns']) / 1e6, 3)],
          [$this->t('Mínimo (suelo de la red)'), number_format(((int) $rtt['min_ns']) / 1e6, 3)],
          [$this->t('Percentil 95'), number_format(((int) $rtt['p95_ns']) / 1e6, 3)],
          [$this->t('Máximo'), number_format(((int) $rtt['max_ns']) / 1e6, 3)],
          [$this->t('Muestras'), (int) $rtt['samples']],
        ],
      ];
    }
    else {
      $build['rtt'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Sin medición de latencia. Ejecuta <code>drush db-audit:rtt</code> desde el mismo host que sirve la aplicación.') . '</p>',
      ];
    }

    return $build;
  }

  /**
   * Texto introductorio con la clave de lectura del informe.
   */
  protected function intro(int $rtt_ns): array {
    if ($rtt_ns === 0) {
      return [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No hay medición de latencia: las estimaciones de margen están desactivadas. Ejecuta <code>drush db-audit:rtt</code>.') . '</p>',
      ];
    }

    return [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('RTT medido contra la base de datos: <strong>@ms ms</strong>. Ese es el coste mínimo de cualquier consulta, la resuelva el servidor en el tiempo que sea; por eso el número de consultas pesa más que su complejidad.', [
        '@ms' => number_format($rtt_ns / 1e6, 3),
      ]) . '</p>',
    ];
  }

  /**
   * Selector de corrida.
   */
  protected function tagFilter(?string $current): array {
    $tags = $this->storage->runTags();
    if ($tags === []) {
      return [];
    }

    $items = [];
    $items[] = ($current === NULL || $current === '')
      ? ['#markup' => '<strong>' . $this->t('Todas') . '</strong>']
      : Link::fromTextAndUrl($this->t('Todas'), Url::fromRoute('<current>'))->toRenderable();

    foreach ($tags as $tag) {
      $items[] = $tag === $current
        ? ['#type' => 'html_tag', '#tag' => 'strong', '#value' => $tag]
        : Link::fromTextAndUrl($tag, Url::fromRoute('<current>', [], ['query' => ['tag' => $tag]]))->toRenderable();
    }

    return [
      '#theme' => 'item_list',
      '#title' => $this->t('Corrida'),
      '#items' => $items,
      '#attributes' => ['class' => ['db-audit-tag-filter']],
    ];
  }

  /**
   * Etiqueta legible de severidad.
   */
  protected function severityLabel(string $severity): string {
    return match ($severity) {
      'critical' => (string) $this->t('Crítico'),
      'high' => (string) $this->t('Alto'),
      'medium' => (string) $this->t('Medio'),
      default => (string) $this->t('Bajo'),
    };
  }

  /**
   * Recorta un texto largo para que quepa en una celda.
   */
  protected function truncate(string $text, int $length): string {
    return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
  }

  /**
   * Corrida seleccionada en la query string.
   */
  protected function currentTag(): ?string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return NULL;
    }
    $tag = (string) $request->query->get('tag', '');
    return $tag !== '' ? $tag : NULL;
  }

}
