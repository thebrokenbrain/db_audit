<?php

declare(strict_types=1);

namespace Drupal\db_audit\Analysis;

/**
 * Un hallazgo del análisis.
 *
 * Lo importante aquí es $savingMs: la estimación de milisegundos que se
 * recuperarían por request si el hallazgo se corrige. Es lo que permite
 * ordenar el trabajo por impacto real en lugar de por intuición, y lo que
 * hace comparable un N+1 de 300 queries con una única consulta lenta.
 */
final class Finding {

  public const SEVERITY_CRITICAL = 'critical';
  public const SEVERITY_HIGH = 'high';
  public const SEVERITY_MEDIUM = 'medium';
  public const SEVERITY_LOW = 'low';

  public function __construct(
    public readonly string $type,
    public readonly string $severity,
    public readonly string $title,
    public readonly string $detail,
    public readonly float $savingMs = 0.0,
    public readonly string $fingerprint = '',
    public readonly string $module = '',
    public readonly string $caller = '',
    public readonly array $data = [],
  ) {}

  /**
   * Peso numérico de la severidad, para ordenar.
   */
  public function severityWeight(): int {
    return match ($this->severity) {
      self::SEVERITY_CRITICAL => 4,
      self::SEVERITY_HIGH => 3,
      self::SEVERITY_MEDIUM => 2,
      default => 1,
    };
  }

  /**
   * Representación apta para almacenar.
   */
  public function toArray(): array {
    return [
      'type' => $this->type,
      'severity' => $this->severity,
      'title' => $this->title,
      'detail' => $this->detail,
      'saving_ms' => $this->savingMs,
      'fingerprint' => $this->fingerprint,
      'module' => $this->module,
      'caller' => $this->caller,
      'data' => $this->data,
    ];
  }

}
