<?php

declare(strict_types=1);

namespace Drupal\db_audit\Driver\Database\auditmysql;

use Drupal\Core\Database\StatementWrapperIterator;
use Drupal\db_audit\Recorder;

require_once __DIR__ . '/bootstrap.php';

/**
 * Statement instrumentado: cronometra cada execute() y lo entrega al Recorder.
 *
 * Este es el punto de captura del módulo. Se eligió aquí, y no en
 * Connection::query() ni en los eventos de base de datos de core, por dos
 * razones:
 *
 * - Connection::query() no ve las ejecuciones que hace el código que llama
 *   directamente a prepareStatement()->execute(), ni las de los objetos de
 *   consulta dinámica que ejecutan el statement por su cuenta.
 * - Los eventos de core (StatementExecutionStartEvent y compañía) exigen que
 *   el contenedor de servicios exista, porque Connection::dispatchEvent()
 *   lanza EventException si no hay event_dispatcher. Eso deja fuera todas las
 *   queries del bootstrap temprano, que en Drupal son muchas y son
 *   precisamente las que se pagan en todos y cada uno de los requests.
 *
 * Envolviendo execute() se captura el 100% del tráfico a la BD desde la
 * primerísima query, sin contenedor, y funciona igual en web, en Drush, en
 * cron y en los queue workers.
 */
class AuditStatement extends StatementWrapperIterator {

  /**
   * {@inheritdoc}
   */
  public function execute($args = [], $options = []) {
    if (!Recorder::isEnabled()) {
      return parent::execute($args, $options);
    }

    // El caller se resuelve ANTES de ejecutar: después, la pila sigue siendo
    // la misma, pero resolverlo aquí mantiene el orden de coste previsible y
    // permite atribuir también las queries que terminan lanzando excepción.
    $caller = Recorder::backtraceLimit() > 0
      ? $this->connection->findCallerFromDebugBacktrace()
      : [];

    $in_transaction = $this->connection->inTransaction();
    $start = hrtime(TRUE);

    try {
      $return = parent::execute($args, $options);
    }
    catch (\Exception $e) {
      Recorder::recordQuery(
        $this->getQueryString(),
        is_array($args) ? $args : [],
        hrtime(TRUE) - $start,
        $caller,
        $this->connection->getTarget(),
        $in_transaction,
        NULL,
        $e->getMessage(),
      );
      throw $e;
    }

    $elapsed = hrtime(TRUE) - $start;

    // Con MYSQL_ATTR_USE_BUFFERED_QUERY (activo por defecto en el driver de
    // core) rowCount() sí devuelve el número de filas de un SELECT. Se accede
    // al statement cliente directamente porque el rowCount() del wrapper
    // lanza RowCountException cuando el contador no fue habilitado, y aquí no
    // queremos alterar el comportamiento de la query auditada.
    $rows = NULL;
    try {
      $rows = $this->clientStatement->rowCount();
    }
    catch (\Throwable) {
      // Sin dato de filas; no es motivo para romper la query del sitio.
    }

    Recorder::recordQuery(
      $this->getQueryString(),
      is_array($args) ? $args : [],
      $elapsed,
      $caller,
      $this->connection->getTarget(),
      $in_transaction,
      is_int($rows) ? $rows : NULL,
    );

    return $return;
  }

}
