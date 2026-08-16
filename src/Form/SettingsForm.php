<?php

declare(strict_types=1);

namespace Drupal\db_audit\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\db_audit\Diagnostics\ConnectionDiagnostics;
use Drupal\db_audit\Storage\AuditStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Umbrales de análisis y utilidades de la auditoría.
 */
class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected readonly StateInterface $auditState,
    protected readonly ConnectionDiagnostics $diagnostics,
    protected readonly AuditStorage $storage,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('state'),
      $container->get('db_audit.diagnostics'),
      $container->get('db_audit.storage'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'db_audit_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['db_audit.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('db_audit.settings');
    $driver = Database::getConnectionInfo('default')['default']['driver'] ?? '';

    $form['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Estado de la captura'),
      '#markup' => $driver === 'auditmysql'
        ? $this->t('El driver instrumentado está activo. La captura se enciende y se apaga en settings.php (<code>$settings[\'db_audit\'][\'enabled\']</code>) o con la variable de entorno <code>DB_AUDIT_ENABLED</code>.')
        : $this->t('El driver instrumentado <strong>no</strong> está activo (driver actual: <code>@driver</code>). No se está capturando nada. Revisa el README.', ['@driver' => $driver]),
    ];

    $form['run_tag'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Etiqueta de la corrida actual'),
      '#default_value' => $this->auditState->get('db_audit.run_tag', ''),
      '#maxlength' => 64,
      '#description' => $this->t('Todo lo que se capture a partir de ahora se marca con esta etiqueta. Sirve para separar corridas de carga y comparar antes y después de un cambio (por ejemplo "base", "con-persistent", "tras-fix-n1").'),
    ];

    $form['thresholds'] = [
      '#type' => 'details',
      '#title' => $this->t('Umbrales de detección'),
      '#open' => TRUE,
    ];
    $form['thresholds']['n_plus_one_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Repeticiones para considerar N+1'),
      '#default_value' => $config->get('n_plus_one_threshold'),
      '#min' => 2,
      '#description' => $this->t('Ejecuciones de la misma consulta, desde el mismo punto del código, dentro de un request.'),
    ];
    $form['thresholds']['slow_query_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('Consulta lenta (ms)'),
      '#default_value' => $config->get('slow_query_ms'),
      '#min' => 1,
    ];
    $form['thresholds']['high_query_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Consultas por request'),
      '#default_value' => $config->get('high_query_count'),
      '#min' => 1,
    ];
    $form['thresholds']['large_resultset'] = [
      '#type' => 'number',
      '#title' => $this->t('Filas devueltas'),
      '#default_value' => $config->get('large_resultset'),
      '#min' => 1,
    ];

    $form['storage'] = [
      '#type' => 'details',
      '#title' => $this->t('Almacenamiento'),
      '#open' => TRUE,
    ];
    $form['storage']['isolation'] = [
      '#type' => 'item',
      '#markup' => AuditStorage::isolated()
        ? $this->t('Los resultados se guardan en la conexión <code>dbaudit</code>, separada de la auditada.')
        : $this->t('Los resultados se guardan en la base de datos auditada. Funciona, pero declarar una conexión <code>dbaudit</code> en settings.php mantiene la medición más limpia.'),
    ];
    $form['storage']['store_queries'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Guardar el detalle de consultas'),
      '#default_value' => $config->get('store_queries'),
      '#description' => $this->t('Sin esto sólo se guarda el resumen por request y los hallazgos.'),
    ];
    $form['storage']['max_stored_queries'] = [
      '#type' => 'number',
      '#title' => $this->t('Máximo de consultas guardadas por request'),
      '#default_value' => $config->get('max_stored_queries'),
      '#min' => 10,
      '#description' => $this->t('Se conservan las más ejecutadas, que son las que revelan los N+1.'),
    ];
    $form['storage']['min_queries_to_store'] = [
      '#type' => 'number',
      '#title' => $this->t('Mínimo de consultas para guardar un request'),
      '#default_value' => $config->get('min_queries_to_store'),
      '#min' => 1,
      '#description' => $this->t('Filtra redirecciones y respuestas triviales.'),
    ];
    $form['storage']['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Días de retención'),
      '#default_value' => $config->get('retention_days'),
      '#min' => 1,
      '#description' => $this->t('El cron de Drupal borra lo más antiguo.'),
    ];

    $form['expose_headers'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Publicar cabeceras X-DbAudit-* en las respuestas'),
      '#default_value' => $config->get('expose_headers'),
      '#description' => $this->t('Sólo se envían a usuarios con permiso para ver los informes. Útil para iterar con curl sobre una ruta concreta.'),
    ];

    $form['actions_extra'] = [
      '#type' => 'details',
      '#title' => $this->t('Utilidades'),
      '#open' => TRUE,
    ];
    $form['actions_extra']['measure'] = [
      '#type' => 'submit',
      '#value' => $this->t('Medir la latencia a la base de datos'),
      '#submit' => ['::measureRtt'],
      '#limit_validation_errors' => [],
    ];
    $form['actions_extra']['purge'] = [
      '#type' => 'submit',
      '#value' => $this->t('Borrar todos los datos capturados'),
      '#submit' => ['::purgeAll'],
      '#limit_validation_errors' => [],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Mide el RTT y lo guarda.
   */
  public function measureRtt(array &$form, FormStateInterface $form_state): void {
    $stats = $this->diagnostics->measureRtt(50);
    $this->messenger()->addStatus($this->t('RTT medido: @min ms (mínimo), @median ms (mediana), @p95 ms (p95) sobre @n muestras.', [
      '@min' => number_format($stats['min_ns'] / 1e6, 3),
      '@median' => number_format($stats['median_ns'] / 1e6, 3),
      '@p95' => number_format($stats['p95_ns'] / 1e6, 3),
      '@n' => $stats['samples'],
    ]));

    $connect = $this->diagnostics->measureConnect(5);
    if (!isset($connect['error'])) {
      $this->messenger()->addStatus($this->t('Abrir una conexión nueva cuesta @total ms: @hs ms de handshake y @init ms de los @n comandos de inicialización.', [
        '@total' => number_format($connect['total_median_ns'] / 1e6, 2),
        '@hs' => number_format($connect['handshake_median_ns'] / 1e6, 2),
        '@init' => number_format($connect['init_median_ns'] / 1e6, 2),
        '@n' => $connect['init_command_count'],
      ]));
    }
  }

  /**
   * Vacía las tablas de auditoría.
   */
  public function purgeAll(array &$form, FormStateInterface $form_state): void {
    $this->storage->purge();
    $this->messenger()->addStatus($this->t('Datos de auditoría borrados.'));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('db_audit.settings')
      ->set('n_plus_one_threshold', (int) $form_state->getValue('n_plus_one_threshold'))
      ->set('slow_query_ms', (int) $form_state->getValue('slow_query_ms'))
      ->set('high_query_count', (int) $form_state->getValue('high_query_count'))
      ->set('large_resultset', (int) $form_state->getValue('large_resultset'))
      ->set('store_queries', (bool) $form_state->getValue('store_queries'))
      ->set('max_stored_queries', (int) $form_state->getValue('max_stored_queries'))
      ->set('min_queries_to_store', (int) $form_state->getValue('min_queries_to_store'))
      ->set('retention_days', (int) $form_state->getValue('retention_days'))
      ->set('expose_headers', (bool) $form_state->getValue('expose_headers'))
      ->save();

    $tag = trim((string) $form_state->getValue('run_tag'));
    if ($tag === '') {
      $this->auditState->delete('db_audit.run_tag');
    }
    else {
      $this->auditState->set('db_audit.run_tag', $tag);
    }

    parent::submitForm($form, $form_state);
  }

}
