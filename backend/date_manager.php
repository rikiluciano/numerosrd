<?php
/**
 * ===================================================================
 * ADMINISTRADOR DE FECHAS - Lottery Scraper RD v4.0
 * ===================================================================
 * 
 * Controla qué fecha procesar en cada ejecución del cron.
 * Lógica:
 *   1. Si hay fechas históricas pendientes → procesa la siguiente
 *   2. Si ya llegamos a hoy → modo tiempo real (extrae el día actual)
 *   3. Nunca procesa la misma fecha dos veces (a menos que se solicite)
 *
 * Zona horaria: America/Santo_Domingo (UTC-4)
 * ===================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

class DateManager {

    private LotteryDB $db;
    private string    $startDate;
    private string    $today;

    public function __construct(LotteryDB $db) {
        $this->db        = $db;
        $this->startDate = EXTRACTION_START;  // '2012-01-01'
        $this->today     = getRDDate();
    }

    // ─── API Pública ────────────────────────────────────────────────

    /**
     * Determina la próxima fecha a procesar
     * 
     * @return array{date: string, mode: string, message: string}
     */
    public function getNextDateToProcess(): array {
        $state = $this->db->getState();

        // ── Modo: primera ejecución o sin historial ──────────────────
        if (empty($state['last_processed_date'])) {
            return $this->buildResult($this->startDate, 'historical', 'Primera ejecución: comenzando desde ' . $this->startDate);
        }

        $lastDate = $state['last_processed_date'];

        // ── Modo: ya estamos en tiempo real ──────────────────────────
        if ($lastDate >= $this->today) {
            return $this->buildResult($this->today, 'realtime', 'Modo tiempo real: procesando el día de hoy');
        }

        // ── Modo: histórico, siguiente día ───────────────────────────
        $nextDate = $this->getNextWorkingDay($lastDate);

        if ($nextDate >= $this->today) {
            // Llegamos a hoy → cambiar a tiempo real
            $this->db->updateState(['current_mode' => 'realtime']);
            return $this->buildResult($this->today, 'realtime', 'Transición a tiempo real: alcanzamos la fecha actual');
        }

        return $this->buildResult($nextDate, 'historical',
            "Histórico: " . $this->getDaysBetween($nextDate, $this->today) . " días restantes"
        );
    }

    /**
     * Marca una fecha como procesada
     */
    public function markDateAsProcessed(string $date): void {
        $mode = ($date >= $this->today) ? 'realtime' : 'historical';

        $this->db->updateState([
            'last_processed_date' => $date,
            'next_date_to_process' => $this->getNextWorkingDay($date),
            'current_mode'        => $mode,
            'last_execution_at'   => getRDTimestamp(),
        ]);

        systemLog("Fecha marcada como procesada: {$date} (modo: {$mode})");
    }

    /**
     * Calcula el progreso histórico (0-100%)
     */
    public function getProgress(): array {
        $state = $this->db->getState();
        $lastDate = $state['last_processed_date'] ?? $this->startDate;

        $totalDays     = $this->getDaysBetween($this->startDate, $this->today);
        $processedDays = $this->getDaysBetween($this->startDate, $lastDate);
        $remainingDays = max(0, $totalDays - $processedDays);
        $percent       = $totalDays > 0 ? round(($processedDays / $totalDays) * 100, 1) : 0;

        // Estimación de tiempo restante (1 min por fecha)
        $remainingMinutes = $remainingDays;
        $remainingHours   = round($remainingMinutes / 60, 1);
        $remainingDaysEst = round($remainingHours / 24, 1);

        return [
            'start_date'         => $this->startDate,
            'last_processed'     => $lastDate,
            'today'              => $this->today,
            'total_days'         => $totalDays,
            'processed_days'     => $processedDays,
            'remaining_days'     => $remainingDays,
            'percent'            => $percent,
            'eta_minutes'        => $remainingMinutes,
            'eta_hours'          => $remainingHours,
            'eta_days'           => $remainingDaysEst,
            'current_mode'       => $state['current_mode'] ?? 'historical',
            'is_historical_done' => $lastDate >= $this->today,
        ];
    }

    /**
     * Obtiene lista de fechas pendientes (las próximas N)
     */
    public function getPendingDates(int $limit = 10): array {
        $state    = $this->db->getState();
        $lastDate = $state['last_processed_date'] ?? null;
        $current  = $lastDate ? $this->getNextWorkingDay($lastDate) : $this->startDate;
        $dates    = [];

        for ($i = 0; $i < $limit; $i++) {
            if ($current > $this->today) break;
            $dates[] = $current;
            $current = $this->getNextWorkingDay($current);
        }

        return $dates;
    }

    /**
     * Fuerza el reprocessado de una fecha específica
     */
    public function forceDate(string $date): array {
        if (!isValidExtractionDate($date)) {
            return ['success' => false, 'error' => "Fecha inválida o fuera de rango: {$date}"];
        }

        // Retroceder el puntero al día anterior para que "getNext" devuelva $date
        $prevDate = $this->getPreviousDay($date);
        $this->db->updateState(['last_processed_date' => $prevDate]);

        return ['success' => true, 'next_date' => $date, 'message' => "Fecha forzada a: {$date}"];
    }

    // ─── Métodos Privados ────────────────────────────────────────────

    /**
     * Calcula el siguiente día calendario (sin saltar fines de semana,
     * ya que las loterías RD operan todos los días)
     */
    private function getNextWorkingDay(string $date): string {
        $ts = strtotime($date . ' +1 day');
        return date('Y-m-d', $ts);
    }

    private function getPreviousDay(string $date): string {
        $ts = strtotime($date . ' -1 day');
        return date('Y-m-d', $ts);
    }

    private function getDaysBetween(string $start, string $end): int {
        $d1 = new DateTime($start);
        $d2 = new DateTime($end);
        return max(0, (int)$d1->diff($d2)->days);
    }

    private function buildResult(string $date, string $mode, string $message): array {
        return [
            'date'    => $date,
            'mode'    => $mode,
            'message' => $message,
            'url'     => SCRAPER_BASE_URL . '/?date=' . formatDateForUrl($date),
        ];
    }
}
