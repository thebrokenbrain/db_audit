<?php

declare(strict_types=1);

namespace Drupal\db_audit\Driver\Database\auditmysql\Install;

use Drupal\mysql\Driver\Database\mysql\Install\Tasks as CoreTasks;

/**
 * Tareas de instalación del driver instrumentado.
 *
 * Hereda íntegramente las comprobaciones del driver mysql de core (versión
 * mínima, disponibilidad de InnoDB, opciones de formulario); sólo cambia el
 * nombre visible para que quede claro en la UI de instalación que el sitio
 * está corriendo sobre el driver de auditoría y no sobre el estándar.
 */
class Tasks extends CoreTasks {

  /**
   * {@inheritdoc}
   */
  public function name() {
    return t('MySQL/MariaDB (instrumentado por Database Audit)');
  }

}
