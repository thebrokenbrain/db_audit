<?php

declare(strict_types=1);

namespace Drupal\db_audit\Driver\Database\auditmysql;

use Drupal\db_audit\Recorder;
use Drupal\mysql\Driver\Database\mysql\Connection as CoreConnection;

// Las clases de soporte están fuera del namespace del driver y por tanto fuera
// del PSR-4 que registra la capa de base de datos durante el bootstrap.
require_once __DIR__ . '/bootstrap.php';

/**
 * Conexión MariaDB/MySQL instrumentada para auditoría de queries.
 *
 * Extiende el driver mysql de core sin alterar su comportamiento: hereda
 * Select, Insert, Update, Delete, Merge, Upsert, Truncate, Schema, Condition y
 * TransactionManager tal cual, porque mysql\Connection los instancia por
 * autoloading estándar en sus propios métodos.
 *
 * Sólo cambian tres cosas:
 * - $statementWrapperClass apunta a AuditStatement, que cronometra execute().
 * - open() cronometra el coste de establecer la conexión (handshake + init).
 * - getDebugBacktrace() acota la profundidad de la pila, porque la versión de
 *   core la captura entera y eso es caro cuando se instrumentan miles de
 *   queries por request.
 */
class Connection extends CoreConnection {

  /**
   * {@inheritdoc}
   */
  protected $statementWrapperClass = AuditStatement::class;

  /**
   * {@inheritdoc}
   */
  public function driver() {
    return 'auditmysql';
  }

  /**
   * {@inheritdoc}
   *
   * Se mantiene 'mysql' para que el resto del sistema (Schema, Views, update
   * hooks, módulos que ramifican por tipo de BD) siga viendo un MySQL normal.
   */
  public function databaseType() {
    return parent::databaseType();
  }

  /**
   * {@inheritdoc}
   *
   * Mide el coste real de abrir la conexión, que cross-AZ es una parte
   * significativa del tiempo de request: TCP + TLS + autenticación y, además,
   * los init_commands que el driver de core ejecuta siempre (SET NAMES y
   * SET sql_mode como mínimo, cada uno un round-trip completo).
   */
  public static function open(array &$connection_options = []) {
    $start = hrtime(TRUE);
    $pdo = parent::open($connection_options);
    $elapsed = hrtime(TRUE) - $start;

    // parent::open() ha rellenado por referencia los defaults de 'pdo' e
    // 'init_commands', así que aquí ya se pueden inspeccionar los valores
    // efectivos y no los que estuvieran escritos en settings.php.
    Recorder::recordConnect(
      $elapsed,
      [
        'host' => $connection_options['host'] ?? ($connection_options['unix_socket'] ?? ''),
        'database' => $connection_options['database'] ?? '',
        'persistent' => !empty($connection_options['pdo'][\PDO::ATTR_PERSISTENT]),
        'emulate_prepares' => !empty($connection_options['pdo'][\PDO::ATTR_EMULATE_PREPARES]),
        // El +1 es el SET NAMES que el driver de core ejecuta siempre con un
        // $pdo->exec() propio, fuera del array 'init_commands'. Sin contarlo,
        // el diagnóstico subestima en un round-trip el coste de cada conexión.
        'init_commands' => count($connection_options['init_commands'] ?? []) + 1,
        'unix_socket' => isset($connection_options['unix_socket']),
      ]
    );

    return $pdo;
  }

  /**
   * {@inheritdoc}
   *
   * Core captura debug_backtrace() sin límite de frames. En una pila típica de
   * Drupal eso son 60-120 entradas por query; con cientos de queries por
   * request el coste deja de ser despreciable y contamina la propia medición.
   *
   * El filtrado de core (removeDatabaseEntriesFromDebugBacktrace) recorre la
   * pila desde el frame más antiguo disponible buscando la primera entrada de
   * la capa de base de datos, así que truncar por el extremo antiguo es seguro
   * mientras el límite deje al menos un frame de aplicación por encima de la
   * capa de BD. El valor por defecto (30) sobra para eso.
   */
  protected function getDebugBacktrace(): array {
    $limit = Recorder::backtraceLimit();
    if ($limit <= 0) {
      return debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    }
    return debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit);
  }

}
