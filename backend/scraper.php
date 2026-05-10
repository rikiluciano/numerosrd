<?php
/**
 * ===================================================================
 * MOTOR DE SCRAPING - Lottery Scraper RD v4.0
 * ===================================================================
 *
 * Extrae resultados de loteriasdominicanas.com por fecha.
 * Selectores HTML del sitio:
 *   div.game-block.company-block-{ID}  → bloque de empresa
 *   div.company-title > div > div > a  → nombre empresa
 *   a.game-title > span                → nombre sorteo
 *   div.session-date                   → hora del sorteo
 *   div.game-scores > span.score       → números ganadores
 * ===================================================================
 */

require_once __DIR__ . '/config.php';

class LotteryScraper {

    private array $config;
    private array $companyMap;

    public function __construct() {
        $this->config     = getScraperConfig();
        $this->companyMap = getCompanyMap();
    }

    // ─── API Pública ─────────────────────────────────────────────────

    /**
     * Scraping completo para una fecha
     *
     * @param string      $date YYYY-MM-DD
     * @param LotteryDB|null $db  Base de datos (opcional, para guardar)
     * @return array
     */
    public function scrape(string $date, $db = null): array {
        $startTime = microtime(true);

        if (!isValidExtractionDate($date)) {
            throw new \InvalidArgumentException("Fecha inválida o fuera de rango: {$date}");
        }

        systemLog("Iniciando scrape para fecha: {$date}");

        // 1. Obtener HTML con reintentos
        $html = $this->fetchWithRetry($date);

        // 2. Parsear resultados
        $parsed = $this->parseHTML($html, $date);

        if (empty($parsed)) {
            systemLog("Sin resultados para la fecha: {$date}", 'warning');
            return $this->buildResult($date, [], 0, 0, 0, 0, 0, microtime(true) - $startTime,
                'Sin resultados para esta fecha (posible día festivo o sin sorteos)');
        }

        systemLog("Resultados extraídos: " . count($parsed), 'info', ['date' => $date]);

        // 3. Guardar en BD si se proporcionó
        $saved      = 0;
        $updated    = 0;
        $duplicates = 0;
        $errors     = 0;

        if ($db) {
            [$saved, $updated, $duplicates, $errors] = $this->saveResults($db, $date, $parsed);
        }

        $duration = microtime(true) - $startTime;

        return $this->buildResult($date, $parsed, $saved, $updated, $duplicates, $errors, count($parsed), $duration);
    }

    /**
     * Obtiene solo el HTML de una página (sin guardar)
     */
    public function fetchPage(string $date): string {
        return $this->fetchWithRetry($date);
    }

    // ─── Scraping ────────────────────────────────────────────────────

    private function fetchWithRetry(string $date): string {
        $attempts = $this->config['retry_attempts'] ?? 3;
        $lastError = null;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $html = $this->doFetch($date);
                if ($i > 1) {
                    systemLog("Éxito en intento #{$i} para {$date}", 'info');
                }
                return $html;
            } catch (\Exception $e) {
                $lastError = $e;
                systemLog("Intento #{$i} fallido para {$date}: " . $e->getMessage(), 'warning');
                if ($i < $attempts) {
                    usleep(($this->config['retry_delay_ms'] ?? 2000) * 1000);
                }
            }
        }

        throw new \RuntimeException("Todos los intentos fallaron para {$date}: " . $lastError->getMessage());
    }

    private function doFetch(string $date): string {
        $urlDate = formatDateForUrl($date);
        $url     = SCRAPER_BASE_URL . '/?date=' . $urlDate;

        systemLog("Fetching: {$url}");

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->config['timeout'] ?? 30,
            CURLOPT_CONNECTTIMEOUT => $this->config['connect_timeout'] ?? 10,
            CURLOPT_USERAGENT      => $this->config['user_agent'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING       => '',  // Acepta gzip automáticamente
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: es-DO,es;q=0.9,en;q=0.8',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Referer: https://loteriasdominicanas.com/',
            ],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException("Error cURL: {$curlError}");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("HTTP {$httpCode} para URL: {$url}");
        }

        if (empty($response) || strlen($response) < 500) {
            throw new \RuntimeException("Respuesta demasiado corta (" . strlen($response) . " bytes)");
        }

        return $response;
    }

    // ─── Parsing HTML ────────────────────────────────────────────────

    /**
     * Parsea el HTML y extrae los resultados de lotería
     * 
     * Estructura del sitio:
     * <div class="game-block ... company-block-{ID} [past]">
     *   <!-- Start Game -->
     *   <div>
     *     <div class="company-title p-2">
     *       <div class="d-flex"><div><a href="/{slug}">{NombreEmpresa}</a></div></div>
     *     </div>
     *     <div class="game-info p-2">
     *       <div class="session-date">{DD-MM}</div>
     *       <a class="game-title" href="/{path}"><span>{NombreSorteo}</span></a>
     *     </div>
     *     <div class="game-scores p-2 ball-mode">
     *       <span class="score special1">{N1}</span>
     *       <span class="score special2">{N2}</span>
     *       <span class="score ">{N3}</span>
     *     </div>
     *   </div>
     *   <!-- End game -->
     * </div>
     */
    public function parseHTML(string $html, string $date): array {
        $results   = [];
        $companyNames = [];

        // Regex para capturar cada bloque game-block
        $blockRegex = '/<div class="game-block([^"]*?)">\s*<!--\s*Start Game\s*-->([\s\S]*?)<!--\s*End game\s*-->/im';

        if (!preg_match_all($blockRegex, $html, $allBlocks, PREG_SET_ORDER)) {
            systemLog("No se encontraron bloques game-block en el HTML", 'warning');
            return [];
        }

        // Primera pasada: mapear IDs de empresa a nombres
        foreach ($allBlocks as $block) {
            $classes = $block[1];
            $content = $block[2];

            if (preg_match('/company-block-(\d+)/', $classes, $idMatch)) {
                $blockId = $idMatch[1];
                if (!isset($companyNames[$blockId])) {
                    if (preg_match('/company-title[\s\S]*?<a[^>]*>([\s\S]*?)<\/a>/i', $content, $aMatch)) {
                        $name = trim(strip_tags($aMatch[1]));
                        if (!empty($name)) {
                            $companyNames[$blockId] = $name;
                        }
                    }
                }
            }
        }

        // Segunda pasada: extraer todos los sorteos
        foreach ($allBlocks as $block) {
            $classes = $block[1];
            $content = $block[2];

            if (!preg_match('/company-block-(\d+)/', $classes, $idMatch)) continue;

            $blockId  = $idMatch[1];
            $isPast   = stripos($classes, 'past') !== false;
            $compInfo = $this->companyMap[$blockId] ?? null;

            // Nombre empresa
            $companyName = $companyNames[$blockId] ?? ($compInfo['name'] ?? "Empresa {$blockId}");
            $companySlug = $compInfo['slug'] ?? "empresa-{$blockId}";
            $companyColor = $compInfo['color'] ?? '#667eea';

            // Nombre sorteo
            $drawName = '';
            if (preg_match('/game-title[^>]*>[\s\S]*?<span>([\s\S]*?)<\/span>/i', $content, $titleMatch)) {
                $drawName = trim(strip_tags($titleMatch[1]));
            }
            if (empty($drawName)) continue;

            // Slug del sorteo (del href)
            $drawSlug = slugifyDrawName($drawName);
            if (preg_match('/game-title[^>]*href="([^"]+)"/i', $content, $hrefMatch)) {
                $pathParts = array_filter(explode('/', trim($hrefMatch[1], '/')));
                if (!empty($pathParts)) {
                    $drawSlug = end($pathParts) ?: $drawSlug;
                }
            }

            // Hora del sorteo
            $drawTime = '';
            if (preg_match('/session-date[^>]*>([\s\S]*?)<\/div>/i', $content, $timeMatch)) {
                $drawTime = trim(preg_replace('/[\s\xc2\xa0]+/', ' ', strip_tags($timeMatch[1])));
                // Normalizar hora: "01 Ene" → solo tomar la parte de hora si la incluye
                if (preg_match('/(\d{1,2}:\d{2})/', $drawTime, $timeOnly)) {
                    $drawTime = $timeOnly[1];
                }
            }

            // Números ganadores
            $numbers     = [];
            $numberTypes = [];
            if (preg_match_all('/<span class="score\s*([^"]*)">(.*?)<\/span>/i', $content, $scoreMatches, PREG_SET_ORDER)) {
                foreach ($scoreMatches as $sm) {
                    $type  = trim($sm[1]);
                    $value = trim($sm[2]);

                    // Aceptar números de 0 a 99 (quinielas) y hasta 99 (lotos)
                    if (!preg_match('/^\d{1,3}$/', $value)) continue;
                    $num = intval($value);
                    if ($num < 0 || $num > 999) continue;

                    $numbers[]     = $num;
                    $numberTypes[] = in_array($type, ['special1', 'special2', 'special3', 'bonus']) ? $type : 'normal';
                }
            }

            if (empty($numbers)) continue;

            $results[] = [
                'companyBlockId' => intval($blockId),
                'companyName'    => $companyName,
                'companySlug'    => $companySlug,
                'companyColor'   => $companyColor,
                'drawName'       => $drawName,
                'drawSlug'       => $companySlug . '-' . $drawSlug,  // Slug único
                'drawTime'       => $drawTime,
                'drawDate'       => $date,
                'numbers'        => $numbers,
                'numberTypes'    => $numberTypes,
                'isPast'         => $isPast,
                'sourceUrl'      => SCRAPER_BASE_URL . '/?date=' . formatDateForUrl($date),
            ];
        }

        return $results;
    }

    // ─── Guardado en BD ──────────────────────────────────────────────

    private function saveResults(LotteryDB $db, string $date, array $results): array {
        $saved = $updated = $duplicates = $errors = 0;

        foreach ($results as $r) {
            try {
                $company = $db->ensureCompany(
                    $r['companyName'],
                    $r['companySlug'],
                    $r['companyColor'],
                    $r['companyBlockId']
                );

                $status = $db->saveDraw(
                    $company['id'],
                    $r['drawName'],
                    $r['drawSlug'],
                    $r['drawDate'],
                    $r['drawTime'],
                    $r['numbers'],
                    $r['numberTypes'],
                    $r['sourceUrl'],
                    $r['isPast']
                );

                match($status) {
                    'saved'     => $saved++,
                    'updated'   => $updated++,
                    'duplicate' => $duplicates++,
                    default     => null,
                };

            } catch (\Exception $e) {
                $errors++;
                systemLog("Error guardando sorteo '{$r['drawName']}' ({$date}): " . $e->getMessage(), 'error');
            }
        }

        return [$saved, $updated, $duplicates, $errors];
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function buildResult(string $date, array $results, int $saved, int $updated,
                                  int $duplicates, int $errors, int $extracted,
                                  float $duration, string $message = ''): array {
        $stats = [
            'extracted'  => $extracted,
            'saved'      => $saved,
            'updated'    => $updated,
            'duplicates' => $duplicates,
            'errors'     => $errors,
            'durationMs' => round($duration * 1000),
        ];

        $success = $errors === 0;
        $msg     = $message ?: "Scrape completado: {$extracted} extraídos, {$saved} guardados, {$updated} actualizados, {$duplicates} duplicados, {$errors} errores";

        return [
            'success' => $success,
            'date'    => $date,
            'results' => $results,
            'stats'   => $stats,
            'message' => $msg,
        ];
    }
}
