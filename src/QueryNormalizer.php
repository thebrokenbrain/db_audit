<?php

declare(strict_types=1);

namespace Drupal\db_audit;

/**
 * Normaliza SQL para poder agrupar ejecuciones equivalentes.
 *
 * Sin normalización, mil ejecuciones de la misma consulta con IDs distintos
 * son mil filas irrelevantes en el informe. Normalizadas, son una sola línea
 * con count=1000, que es exactamente el patrón N+1 que buscamos.
 *
 * Hay dos particularidades de Drupal que obligan a ir más allá de una
 * normalización SQL genérica:
 *
 * - Los placeholders llevan un contador incremental por consulta
 *   (:db_condition_placeholder_0, :db_insert_placeholder_1...). Dos consultas
 *   idénticas generadas en puntos distintos del código pueden numerarlos de
 *   forma diferente, y sin unificarlos no agruparían.
 * - Las cláusulas IN se expanden a tantos placeholders como valores haya
 *   (Connection::expandArguments), de modo que la misma consulta con 3 o con
 *   30 valores produce SQL distinto.
 */
final class QueryNormalizer {

  /**
   * Reduce una consulta a su forma canónica.
   */
  public static function normalize(string $sql): string {
    // Comentarios de bloque y de línea.
    $sql = preg_replace('!/\*.*?\*/!s', ' ', $sql) ?? $sql;
    $sql = preg_replace('/--[^\n]*/', ' ', $sql) ?? $sql;

    // Placeholders numerados de Drupal. Una sola regla cubre las dos familias:
    // los que generan los constructores de consultas
    // (:db_condition_placeholder_0, :db_insert_placeholder_1) y los que produce
    // Connection::expandArguments() al expandir un array, que sustituye el []
    // por un doble guion bajo y añade el índice (:cids[] pasa a :cids__0,
    // :cids__1...). Sin esta segunda familia, cada consulta de caché con un
    // número distinto de claves generaría una huella distinta y los patrones
    // repetidos no llegarían a agruparse.
    //
    // El sufijo exigido es _ seguido de dígitos hasta el final del
    // identificador, así que no toca nombres como :field_foo2.
    $sql = preg_replace('/:[A-Za-z_][A-Za-z0-9_]*_\d+/', ':ph', $sql) ?? $sql;

    // Literales de cadena, incluidas las comillas escapadas y las dobladas.
    $sql = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", '?', $sql) ?? $sql;

    // Literales numéricos. El \b inicial evita tocar los dígitos que forman
    // parte de un identificador (field_foo2, node_field_data...).
    $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;

    // Listas de placeholders de longitud variable: IN (:ph, :ph, :ph) y
    // VALUES (?, ?), (?, ?) colapsan a una sola forma.
    $sql = preg_replace('/\(\s*(?::ph|\?)\s*(?:,\s*(?::ph|\?)\s*)+\)/', '(...)', $sql) ?? $sql;
    $sql = preg_replace('/(\(\.\.\.\))(\s*,\s*\(\.\.\.\))+/', '$1', $sql) ?? $sql;

    // Espacios en blanco.
    $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;

    return trim($sql);
  }

  /**
   * Huella estable de una consulta normalizada.
   */
  public static function fingerprint(string $normalized): string {
    return hash('xxh3', $normalized);
  }

  /**
   * Tipo de operación.
   */
  public static function operation(string $normalized): string {
    if (!preg_match('/^\s*\(*\s*([A-Za-z]+)/', $normalized, $m)) {
      return 'OTHER';
    }
    $op = strtoupper($m[1]);
    $known = [
      'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'SET', 'SHOW',
      'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'START', 'COMMIT', 'ROLLBACK',
      'SAVEPOINT', 'RELEASE', 'EXPLAIN', 'DESCRIBE', 'LOCK', 'UNLOCK',
    ];
    return in_array($op, $known, TRUE) ? $op : 'OTHER';
  }

  /**
   * Tablas mencionadas en la consulta, como lista separada por comas.
   *
   * Contempla los tres estilos de citado que pueden aparecer: comillas dobles
   * (Drupal fuerza sql_mode ANSI en MySQL/MariaDB), backticks y sin citar.
   */
  public static function tables(string $normalized): string {
    $pattern = '/\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+(?:"([^"]+)"|`([^`]+)`|([A-Za-z_][A-Za-z0-9_]*))/i';
    if (!preg_match_all($pattern, $normalized, $matches, PREG_SET_ORDER)) {
      return '';
    }

    $tables = [];
    foreach ($matches as $match) {
      $name = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : ($match[3] ?? ''));
      $name = trim($name);
      if ($name === '' || strcasecmp($name, 'SELECT') === 0) {
        continue;
      }
      $tables[$name] = TRUE;
    }

    return mb_substr(implode(',', array_slice(array_keys($tables), 0, 8)), 0, 255);
  }

}
