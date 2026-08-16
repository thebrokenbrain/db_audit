<?php

declare(strict_types=1);

namespace Drupal\db_audit\Diagnostics;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\State\StateInterface;

/**
 * Mide la latencia real entre la aplicación y la base de datos.
 *
 * Todo el análisis del módulo descansa sobre un número: cuánto cuesta un
 * round-trip. Ese número no se puede suponer, hay que medirlo desde la propia
 * aplicación y contra la instancia real, porque es exactamente lo que cambia al
 * pasar de una topología on-premise a una repartida entre zonas de
 * disponibilidad.
 *
 * La medida se toma con SELECT 1: una consulta cuyo tiempo de ejecución en el
 * servidor es esencialmente cero, de modo que lo que se cronometra es la ida y
 * la vuelta.
 *
 * Como estimación del RTT se usa la MEDIANA de la serie, no el mínimo ni la
 * media:
 *
 * - El mínimo describe el suelo físico de la red, el mejor caso posible. Es un
 *   dato interesante, pero subestima el coste: al eliminar N consultas no se
 *   ahorra N veces el mejor caso, sino N veces el caso típico. Usarlo como
 *   referencia infravalora sistemáticamente la parte del tiempo que es red,
 *   que es justo la conclusión que el módulo existe para dar.
 * - La media se va detrás de cualquier pico aislado de contención.
 *
 * La mediana es lo que paga una consulta cualquiera, y es estable frente a
 * ambos extremos. El mínimo se sigue reportando como referencia del suelo.
 */
class ConnectionDiagnostics {

  public function __construct(
    protected readonly Connection $connection,
    protected readonly StateInterface $state,
  ) {}

  /**
   * Mide el RTT contra la base de datos.
   *
   * @param int $samples
   *   Número de mediciones.
   * @param bool $persist
   *   Si se guarda el resultado en state para que lo use el analizador.
   *
   * @return array
   *   Estadísticas en milisegundos.
   */
  public function measureRtt(int $samples = 50, bool $persist = TRUE): array {
    $samples = max(5, $samples);
    $times = [];

    // Primera consulta descartada: paga la apertura perezosa de la conexión y
    // el primer prepare, y falsearía el suelo.
    $this->connection->query('SELECT 1')->fetchField();

    for ($i = 0; $i < $samples; $i++) {
      $start = hrtime(TRUE);
      $this->connection->query('SELECT 1')->fetchField();
      $times[] = hrtime(TRUE) - $start;
    }

    sort($times);
    $count = count($times);
    $p95_index = min($count - 1, (int) floor($count * 0.95));
    $stats = [
      'samples' => $count,
      'min_ns' => $times[0],
      'median_ns' => $times[intdiv($count, 2)],
      'p95_ns' => $times[$p95_index],
      'max_ns' => $times[$count - 1],
      'avg_ns' => (int) (array_sum($times) / $count),
    ];

    if ($persist) {
      $this->state->set('db_audit.rtt_ns', $stats['median_ns']);
      $this->state->set('db_audit.rtt_samples', $stats);
      $this->state->set('db_audit.rtt_measured', time());
    }

    return $stats;
  }

  /**
   * Mide el coste de abrir una conexión nueva.
   *
   * Abre conexiones PDO propias, con las mismas credenciales y opciones que
   * usa Drupal, en lugar de cerrar y reabrir la del sitio: así la medición no
   * interfiere con el request en curso ni con transacciones abiertas.
   *
   * Separa el handshake (TCP + TLS + autenticación) de los comandos de
   * inicialización que el driver de core ejecuta siempre, porque se corrigen
   * de formas distintas: el primero con conexiones persistentes o un pooler,
   * los segundos revisando la configuración de la conexión.
   */
  public function measureConnect(int $samples = 5): array {
    $info = Database::getConnectionInfo('default')['default'] ?? [];
    if ($info === []) {
      return ['error' => 'No hay información de conexión disponible.'];
    }

    $dsn = $this->buildDsn($info);
    $options = [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_EMULATE_PREPARES => TRUE,
      \PDO::ATTR_PERSISTENT => FALSE,
    ];

    $handshake = [];
    $init = [];
    $init_commands = $this->initCommands($info);

    for ($i = 0; $i < max(1, $samples); $i++) {
      $start = hrtime(TRUE);
      $pdo = new \PDO($dsn, $info['username'] ?? '', $info['password'] ?? '', $options);
      $handshake[] = hrtime(TRUE) - $start;

      $start = hrtime(TRUE);
      foreach ($init_commands as $sql) {
        $pdo->exec($sql);
      }
      $init[] = hrtime(TRUE) - $start;

      unset($pdo);
    }

    sort($handshake);
    sort($init);

    return [
      'samples' => count($handshake),
      'handshake_min_ns' => $handshake[0],
      'handshake_median_ns' => $handshake[intdiv(count($handshake), 2)],
      'init_min_ns' => $init[0],
      'init_median_ns' => $init[intdiv(count($init), 2)],
      'init_command_count' => count($init_commands),
      'total_median_ns' => $handshake[intdiv(count($handshake), 2)] + $init[intdiv(count($init), 2)],
    ];
  }

  /**
   * Estado efectivo de la conexión, para el diagnóstico.
   */
  public function connectionInfo(): array {
    $info = Database::getConnectionInfo('default')['default'] ?? [];
    $pdo_options = $info['pdo'] ?? [];

    $result = [
      'driver' => $info['driver'] ?? '',
      'host' => $info['host'] ?? ($info['unix_socket'] ?? ''),
      'port' => $info['port'] ?? 3306,
      'database' => $info['database'] ?? '',
      'unix_socket' => isset($info['unix_socket']),
      'persistent' => !empty($pdo_options[\PDO::ATTR_PERSISTENT]),
      // El driver de core lo pone a TRUE por defecto, así que la ausencia de
      // la clave equivale a activado.
      'emulate_prepares' => !array_key_exists(\PDO::ATTR_EMULATE_PREPARES, $pdo_options)
        || (bool) $pdo_options[\PDO::ATTR_EMULATE_PREPARES],
      'isolation_level' => $info['isolation_level'] ?? NULL,
      'init_commands' => count($this->initCommands($info)),
      'has_replica' => !empty(Database::getConnectionInfo('default')['replica']),
      'audit_storage_isolated' => !empty(Database::getConnectionInfo('dbaudit')),
    ];

    // Datos que sólo el servidor puede responder.
    try {
      $result['server_version'] = (string) $this->connection->query('SELECT VERSION()')->fetchField();
      $ssl = $this->connection->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetchAssoc();
      $cipher = is_array($ssl) ? (string) ($ssl['Value'] ?? '') : '';
      $result['tls'] = $cipher !== '';
      $result['tls_cipher'] = $cipher;
      $result['wait_timeout'] = (string) $this->connection->query('SELECT @@wait_timeout')->fetchField();
      $result['max_connections'] = (string) $this->connection->query('SELECT @@max_connections')->fetchField();
      $result['threads_connected'] = (string) ($this->connection->query("SHOW STATUS LIKE 'Threads_connected'")->fetchAssoc()['Value'] ?? '');
    }
    catch (\Throwable $e) {
      $result['server_error'] = $e->getMessage();
    }

    return $result;
  }

  /**
   * Reconstruye el DSN igual que lo hace el driver de core.
   */
  protected function buildDsn(array $info): string {
    if (isset($info['unix_socket'])) {
      $dsn = 'mysql:unix_socket=' . $info['unix_socket'];
    }
    else {
      $dsn = 'mysql:host=' . ($info['host'] ?? '') . ';port=' . (empty($info['port']) ? 3306 : $info['port']);
    }
    $dsn .= ';charset=utf8mb4';
    if (!empty($info['database'])) {
      $dsn .= ';dbname=' . $info['database'];
    }
    return $dsn;
  }

  /**
   * Comandos de inicialización que el driver ejecuta al abrir la conexión.
   */
  protected function initCommands(array $info): array {
    $commands = $info['init_commands'] ?? [];
    $commands += [
      'sql_mode' => "SET sql_mode = 'ANSI,TRADITIONAL'",
    ];
    if (!empty($info['isolation_level'])) {
      $commands += [
        'isolation_level' => 'SET SESSION TRANSACTION ISOLATION LEVEL ' . strtoupper($info['isolation_level']),
      ];
    }
    // SET NAMES lo ejecuta el driver siempre, aparte de init_commands.
    $names = !empty($info['collation'])
      ? 'SET NAMES utf8mb4 COLLATE ' . $info['collation']
      : 'SET NAMES utf8mb4';

    return array_merge([$names], array_values($commands));
  }

}
