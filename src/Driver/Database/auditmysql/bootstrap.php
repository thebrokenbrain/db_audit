<?php

/**
 * @file
 * Carga las clases de soporte del módulo sin depender del autoloader.
 *
 * Database::addConnectionInfo() registra un PSR-4 sólo para el namespace del
 * driver y para los declarados en 'dependencies'. El namespace raíz del módulo
 * (Drupal\db_audit) no lo registra Drupal hasta que inicializa el contenedor de
 * servicios, y para entonces ya se han ejecutado decenas de consultas del
 * bootstrap: precisamente las que este módulo existe para capturar.
 *
 * Sin esto, la primera consulta del sitio moriría con un "class not found" y el
 * sitio no arrancaría. Se cargan a mano y de forma idempotente.
 */

declare(strict_types=1);

$db_audit_src = dirname(__DIR__, 3);

foreach (['Recorder', 'QueryNormalizer', 'CallerContext'] as $db_audit_class) {
  if (!class_exists('Drupal\\db_audit\\' . $db_audit_class, FALSE)) {
    $db_audit_file = $db_audit_src . '/' . $db_audit_class . '.php';
    if (is_file($db_audit_file)) {
      require_once $db_audit_file;
    }
  }
}

unset($db_audit_src, $db_audit_class, $db_audit_file);
