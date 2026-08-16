<?php

declare(strict_types=1);

namespace Drupal\db_audit;

/**
 * Atribuye cada query al código que la lanzó.
 *
 * Saber que se ejecutan 400 queries no sirve de nada si no se sabe quién las
 * lanza. Esta clase convierte el frame que devuelve
 * Connection::findCallerFromDebugBacktrace() en algo accionable: fichero
 * relativo, línea, clase, método y —sobre todo— el módulo responsable, que es
 * el nivel al que se decide si algo se arregla, se cachea o se reporta al
 * mantenedor del contrib.
 */
final class CallerContext {

  /**
   * Raíz de Drupal, cacheada para no resolverla en cada query.
   */
  private static ?string $root = NULL;

  /**
   * Caché de path de fichero a módulo responsable.
   */
  private static array $moduleCache = [];

  /**
   * Convierte un frame de backtrace en información de atribución.
   */
  public static function fromBacktraceEntry(array $caller): array {
    $file = (string) ($caller['file'] ?? '');
    $relative = self::relativePath($file);

    return [
      'file' => $relative,
      'line' => (int) ($caller['line'] ?? 0),
      'class' => mb_substr((string) ($caller['class'] ?? ''), 0, 255),
      'function' => mb_substr((string) ($caller['function'] ?? ''), 0, 255),
      'module' => $file === '' ? 'unknown' : self::moduleFromPath($file),
    ];
  }

  /**
   * Path relativo a la raíz de Drupal.
   */
  public static function relativePath(string $file): string {
    if ($file === '') {
      return 'unknown';
    }
    $root = self::root();
    if ($root !== '' && str_starts_with($file, $root)) {
      $file = ltrim(substr($file, strlen($root)), '/');
    }
    return mb_substr($file, 0, 512);
  }

  /**
   * Deduce el módulo, tema o paquete responsable a partir del path.
   */
  public static function moduleFromPath(string $file): string {
    if (isset(self::$moduleCache[$file])) {
      return self::$moduleCache[$file];
    }

    $path = str_replace('\\', '/', $file);
    $module = 'unknown';

    // El orden importa: core/modules debe comprobarse antes que el patrón
    // genérico /modules/, y contrib/custom antes que /modules/ a secas.
    $patterns = [
      '#/core/modules/([^/]+)/#' => 'core:%s',
      '#/core/profiles/([^/]+)/#' => 'core-profile:%s',
      '#/core/lib/#' => 'core',
      '#/core/includes/#' => 'core',
      '#/modules/contrib/([^/]+)/#' => 'contrib:%s',
      '#/modules/custom/([^/]+)/#' => 'custom:%s',
      '#/themes/contrib/([^/]+)/#' => 'theme:%s',
      '#/themes/custom/([^/]+)/#' => 'theme:%s',
      '#/profiles/contrib/([^/]+)/#' => 'profile:%s',
      '#/profiles/custom/([^/]+)/#' => 'profile:%s',
      '#/modules/([^/]+)/#' => 'module:%s',
      '#/themes/([^/]+)/#' => 'theme:%s',
      '#/profiles/([^/]+)/#' => 'profile:%s',
      '#/vendor/([^/]+/[^/]+)/#' => 'vendor:%s',
    ];

    foreach ($patterns as $pattern => $format) {
      if (preg_match($pattern, $path, $matches)) {
        $module = isset($matches[1]) ? sprintf($format, $matches[1]) : $format;
        break;
      }
    }

    // Cota defensiva: en un proceso que tocara muchísimos ficheros distintos
    // esta caché podría crecer sin control.
    if (count(self::$moduleCache) < 2000) {
      self::$moduleCache[$file] = $module;
    }

    return $module;
  }

  /**
   * Raíz de Drupal en disco.
   */
  private static function root(): string {
    if (self::$root === NULL) {
      self::$root = defined('DRUPAL_ROOT') ? rtrim((string) constant('DRUPAL_ROOT'), '/') : '';
    }
    return self::$root;
  }

}
