<?php

declare(strict_types=1);

namespace Drupal\db_audit\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\db_audit\AuditFlusher;
use Drupal\db_audit\Recorder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Cierra la auditoría de las peticiones web y expone las cabeceras de
 * diagnóstico.
 */
class AuditSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected readonly AuditFlusher $flusher,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly RouteMatchInterface $routeMatch,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Lo más tarde posible, para que la ruta y el código de estado ya sean
      // los definitivos.
      KernelEvents::RESPONSE => ['onResponse', -1000],
      KernelEvents::TERMINATE => ['onTerminate', 1000],
    ];
  }

  /**
   * Recoge el contexto del request y, si procede, publica las cabeceras.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!Recorder::isEnabled()) {
      return;
    }

    $request = $event->getRequest();
    $response = $event->getResponse();

    Recorder::addContext([
      'route' => (string) ($this->routeMatch->getRouteName() ?? ''),
      'path' => $request->getPathInfo(),
      'method' => $request->getMethod(),
      'status' => $response->getStatusCode(),
      'uid' => (int) $this->currentUser->id(),
      'authenticated' => $this->currentUser->isAuthenticated(),
      'hostname' => gethostname() ?: '',
    ]);

    if (!$this->shouldExposeHeaders()) {
      return;
    }

    // Cabeceras de diagnóstico: permiten iterar con curl sobre una ruta
    // concreta sin entrar en la interfaz, que es como se trabaja mientras se
    // está afinando una página.
    $queries = Recorder::getQueryCount();
    $db_ms = Recorder::getTotalNs() / 1e6;
    $connect_ms = Recorder::getConnectNs() / 1e6;

    $response->headers->set('X-DbAudit-Id', Recorder::getRequestId());
    $response->headers->set('X-DbAudit-Queries', (string) $queries);
    $response->headers->set('X-DbAudit-Db-Ms', number_format($db_ms, 2, '.', ''));
    $response->headers->set('X-DbAudit-Connect-Ms', number_format($connect_ms, 2, '.', ''));

    // Cuánto de ese tiempo es, estimadamente, pura red.
    $rtt_ns = (int) $this->state->get('db_audit.rtt_ns', 0);
    if ($rtt_ns > 0) {
      $response->headers->set('X-DbAudit-Net-Ms', number_format(($queries * $rtt_ns) / 1e6, 2, '.', ''));
    }
  }

  /**
   * Vuelca la auditoría una vez enviada la respuesta.
   */
  public function onTerminate(TerminateEvent $event): void {
    $this->flusher->flush(['closed_by' => 'terminate']);
  }

  /**
   * Decide si se pueden publicar las cabeceras en esta respuesta.
   */
  protected function shouldExposeHeaders(): bool {
    if (!($this->configFactory->get('db_audit.settings')->get('expose_headers') ?? FALSE)) {
      return FALSE;
    }
    // Aunque las cabeceras no revelan datos del sitio, sí revelan su forma
    // interna, así que se limitan a quien puede ver los informes.
    return $this->currentUser->hasPermission('view db audit reports');
  }

}
