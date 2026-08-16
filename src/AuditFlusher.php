<?php

declare(strict_types=1);

namespace Drupal\db_audit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\db_audit\Analysis\Analyzer;
use Drupal\db_audit\Storage\AuditStorage;

/**
 * Cierra la unidad de trabajo auditada: analiza y persiste, una sola vez.
 *
 * Se invoca por dos caminos distintos y es idempotente a propósito:
 *
 * - Desde KernelEvents::TERMINATE en peticiones web, que con PHP-FPM se
 *   ejecuta después de que la respuesta haya salido hacia el cliente y por
 *   tanto no penaliza el tiempo percibido.
 * - Desde una función de shutdown registrada por el Recorder, que es la única
 *   vía que cubre Drush, cron, los queue workers y también los errores
 *   fatales, donde el evento de terminación nunca llega a dispararse.
 */
class AuditFlusher {

  /**
   * Evita el doble volcado cuando llegan los dos caminos.
   */
  protected bool $flushed = FALSE;

  public function __construct(
    protected readonly Analyzer $analyzer,
    protected readonly AuditStorage $storage,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Analiza y persiste lo capturado.
   *
   * @param array $context
   *   Metadatos adicionales a fusionar antes de analizar.
   */
  public function flush(array $context = []): void {
    if ($this->flushed) {
      return;
    }
    $this->flushed = TRUE;

    if (!Recorder::isEnabled() && Recorder::getQueryCount() === 0) {
      return;
    }

    if ($context !== []) {
      Recorder::addContext($context);
    }

    $snapshot = Recorder::snapshot();
    if ($snapshot === [] || (int) ($snapshot['total_queries'] ?? 0) === 0) {
      return;
    }

    try {
      $findings = $this->analyzer->analyze($snapshot);
      $this->storage->write($snapshot, $findings);
    }
    catch (\Throwable $e) {
      // Nunca a costa del sitio auditado.
      if (\Drupal::hasService('logger.factory')) {
        \Drupal::logger('db_audit')->error('Fallo al cerrar la auditoría: @message', ['@message' => $e->getMessage()]);
      }
    }
  }

  /**
   * Prepara una nueva unidad de trabajo.
   *
   * Pensado para procesos largos que quieren un registro por elemento en lugar
   * de uno por proceso: un queue worker que procesa mil elementos o un comando
   * de migración. Sin esto, todo el proceso se agregaría en una sola fila y se
   * perdería la capacidad de comparar entre elementos.
   */
  public function startNewUnit(array $context = []): void {
    $this->flushed = FALSE;
    Recorder::reset();
    if ($context !== []) {
      Recorder::addContext($context);
    }
  }

}
