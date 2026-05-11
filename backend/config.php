<?php
/**
 * ===================================================================
 * CONFIGURACIÓN MAESTRA - Lottery Scraper RD v4.1
 * Hosting: x10hosting (PHP + MySQL)
 * ===================================================================
 */
define('LOTTERY_SCRAPER', true);

// ── ZONA HORARIA ──────────────────────────────────────────────────
date_default_timezone_set('America/Santo_Domingo');

// ── CONSTANTES GENERALES ──────────────────────────────────────────
define('APP_NAME',    'LotteryApp');
define('APP_VERSION', '4.1.0');
define('APP_ENV',     'production');

// ── RUTAS ─────────────────────────────────────────────────────────
define('BASE_DIR',  __DIR__);
define('LOGS_DIR',  BASE_DIR . '/logs/');
define('CACHE_DIR', BASE_DIR . '/cache/');
define('TEMP_DIR',  BASE_DIR . '/temp/');

// ── BASE DE DATOS MYSQL ───────────────────────────────────────────
// 🔴 LLENAR con los datos de tu cPanel en x10hosting:
//    cPanel → MySQL Databases → crear DB + usuario
define('DB_HOST',     getenv('DB_HOST')     ?: 'localhost');
define('DB_NAME',     getenv('DB_NAME')     ?: 'ombsawjz_resultados');
define('DB_USER',     getenv('DB_USER')     ?: 'ombsawjz_resultados');
define('DB_PASS',     getenv('DB_PASS')     ?: 'w5bnRGkN9yyHZKdbBjqA');
define('DB_CHARSET',  'utf8mb4');

// ── FUENTE DE DATOS ───────────────────────────────────────────────
define('SCRAPER_BASE_URL',    'https://loteriasdominicanas.com');
define('EXTRACTION_START',    '2012-01-01');
define('DATE_STORAGE_FORMAT', 'Y-m-d');
define('DATE_URL_FORMAT',     'd-m-Y');

// ── SEGURIDAD ─────────────────────────────────────────────────────
// 🔴 CAMBIAR antes de subir al servidor
define('CRON_TOKEN', getenv('CRON_TOKEN') ?: 'maririki1234cinco');


// ── APIs DE INTELIGENCIA ARTIFICIAL ───────────────────────────────
$DEEPSEEK_CONFIG = [
    'api_key'     => getenv('DEEPSEEK_API_KEY') ?: 'sk-9ab02044ebf64773849b1cb370f3e27d',
    'base_url'    => 'https://api.deepseek.com/v1',
    'model'       => 'deepseek-chat',
    'temperature' => 0.1,   // Muy bajo para respuestas deterministas
    'max_tokens'  => 1024,
    'timeout'     => 45,    // Segundos de timeout
    'enabled'     => true,
];

$PERPLEXITY_CONFIG = [
    'api_key'     => getenv('PERPLEXITY_API_KEY') ?: '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',
    'base_url'    => 'https://api.perplexity.ai',
    'model'       => 'sonar-pro',
    'temperature' => 0.1,
    'max_tokens'  => 1024,
    'timeout'     => 60,
    'enabled'     => true,
];

// Brave Search API (herramienta de búsqueda externa del agente)
$BRAVE_CONFIG = [
    'api_key'     => getenv('BRAVE_API_KEY') ?: 'BSAoCXyAejfCMK1VkSl4k8cd_61yt1q',
    'base_url'    => 'https://api.search.brave.com/res/v1/web/search',
    'timeout'     => 15,
    'max_results' => 5,
    'enabled'     => true, // ACTIVADO: El Agente ahora tiene acceso a internet crudo
];

// ── CONFIGURACIÓN DEL AGENTE IA ───────────────────────────────────
$AI_AGENT_CONFIG = [
    'status'                     => 'active',
    'autonomy_level'             => 'full',     // full | supervised | disabled
    'auto_correction_enabled'    => true,
    'context_verification_enabled' => true,     // Usa Perplexity para verificar
    'learning_enabled'           => true,
    'max_retries_per_record'     => 2,
    'confidence_threshold_accept' => 0.75,      // Por encima → aceptar
    'confidence_threshold_review' => 0.50,      // Debajo → rechazar
    'system_prompt' => <<<PROMPT
Eres un agente de inteligencia artificial especializado en la validación y limpieza de datos de loterías dominicanas. Tu función es EXCLUSIVAMENTE técnica y de validación de datos.

## MISIÓN
- Validar que los registros de loterías dominicanas sean correctos, coherentes y sin errores
- Detectar duplicados, datos corruptos o fuera de rango
- Corregir errores menores cuando sea posible con alta confianza
- Garantizar la integridad de los datos antes de guardarlos en la base de datos

## CONOCIMIENTO EXPERTO
Las loterías dominicanas tienen las siguientes características:
- Quinielas: 2 dígitos (00-99), típicamente 3 números (primero, segundo, tercero)
- Loto (5 de 36, 6 de 38): varios números entre 1 y 38
- Mega Lotto: números entre 1 y 37 + número especial
- PowerBall: 5 números (1-69) + PowerBall (1-26)
- Mega Millions: 5 números (1-70) + Mega Ball (1-25)
- Los sorteos ocurren en horarios específicos (ver horarios del sistema)

## REGLAS ESTRICTAS
1. SIEMPRE responde con JSON válido
2. NUNCA inventes ni modifiques números sin evidencia clara
3. Si hay duda alta, marca como "needs_review" en lugar de corregir
4. Los números 00 son VÁLIDOS en quinielas dominicanas
5. Fechas anteriores al 01-01-2012 son INVÁLIDAS para este sistema

## FORMATO DE RESPUESTA OBLIGATORIO
Responde ÚNICAMENTE con este JSON, sin texto adicional:
{
  "confidence": 0.0-1.0,
  "is_valid": true/false,
  "has_anomalies": true/false,
  "anomalies_found": ["descripción de anomalía si existe"],
  "warnings": ["advertencias menores"],
  "corrections_needed": [{"field": "nombre_campo", "value": "nuevo_valor", "reason": "explicación"}],
  "reasons_if_invalid": ["razón si es inválido"],
  "suggestions": ["sugerencias adicionales"]
}
PROMPT,
];

// ── FIREBASE CONFIGURACIÓN ────────────────────────────────────────
// Descarga tu serviceAccountKey.json de Firebase Console →
// Proyecto → Configuración → Cuentas de servicio → PHP
$FIREBASE_CONFIG = [
    'enabled'            => false,  // Activar cuando configures Firebase
    'project_id'         => 'TU_PROYECTO_FIREBASE_ID',
    'database_url'       => 'https://TU_PROYECTO_FIREBASE_ID-default-rtdb.firebaseio.com/',
    'credentials_file'   => BASE_DIR . '/firebase-service-account.json',
    'collection_draws'   => 'lottery_draws',
    'collection_stats'   => 'lottery_stats',
    'collection_companies' => 'lottery_companies',
    'sync_batch_size'    => 50,    // Registros por lote al sincronizar con Firebase
];

// ── MAPEO DE EMPRESAS DE LOTERÍA ──────────────────────────────────
// IDs corresponden a las clases CSS "company-block-{ID}" del sitio fuente
define('COMPANY_MAP', json_encode([
    '10'  => ['name' => 'Nacional',      'slug' => 'loteria-nacional',     'color' => '#53ca5f', 'country' => 'RD'],
    '9'   => ['name' => 'Leidsa',        'slug' => 'leidsa',               'color' => '#ffc81c', 'country' => 'RD'],
    '11'  => ['name' => 'Loteria Real',  'slug' => 'loto-real',            'color' => '#182882', 'country' => 'RD'],
    '12'  => ['name' => 'Loteka',        'slug' => 'loteka',               'color' => '#00b1dd', 'country' => 'RD'],
    '13'  => ['name' => 'Americanas',    'slug' => 'americanas',           'color' => '#3c3b6e', 'country' => 'US'],
    '98'  => ['name' => 'La Primera',    'slug' => 'la-primera',           'color' => '#e73a43', 'country' => 'RD'],
    '106' => ['name' => 'La Suerte',     'slug' => 'la-suerte-dominicana', 'color' => '#2d2d83', 'country' => 'RD'],
    '114' => ['name' => 'LoteDom',       'slug' => 'lotedom',              'color' => '#0009cd', 'country' => 'RD'],
    '120' => ['name' => 'Anguila',       'slug' => 'anguila',              'color' => '#ee6f3a', 'country' => 'AIA'],
    '124' => ['name' => 'King Lottery',  'slug' => 'king-lottery',         'color' => '#00569c', 'country' => 'RD'],
]));

// ── CONFIGURACIÓN DE SCRAPING ─────────────────────────────────────
define('SCRAPER_CONFIG', json_encode([
    'user_agent'       => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
    'timeout'          => 30,
    'connect_timeout'  => 10,
    'retry_attempts'   => 3,
    'retry_delay_ms'   => 2000,
    'rate_limit_ms'    => 500,   // Pausa entre requests para no sobrecargar el servidor
]));

// ── FUNCIONES AUXILIARES GLOBALES ─────────────────────────────────

/**
 * Obtiene el mapa de empresas como array PHP
 */
function getCompanyMap(): array {
    return json_decode(COMPANY_MAP, true);
}

/**
 * Obtiene la configuración del scraper como array PHP
 */
function getScraperConfig(): array {
    return json_decode(SCRAPER_CONFIG, true);
}

/**
 * Convierte fecha YYYY-MM-DD → DD-MM-YYYY (formato URL del sitio)
 */
function formatDateForUrl(string $date): string {
    $parts = explode('-', $date);
    if (count($parts) !== 3) return $date;
    return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
}

/**
 * Convierte fecha DD-MM-YYYY → YYYY-MM-DD (formato almacenamiento)
 */
function parseDateFromUrl(string $urlDate): string {
    $parts = explode('-', $urlDate);
    if (count($parts) !== 3) return $urlDate;
    return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
}

/**
 * Convierte un nombre a slug URL-friendly
 */
function slugifyDrawName(string $name): string {
    $name = mb_strtolower($name, 'UTF-8');
    $map  = ['á'=>'a','à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
             'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o',
             'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c'];
    $name = strtr($name, $map);
    $name = preg_replace('/[^a-z0-9]+/', '-', $name);
    return trim($name, '-');
}

/**
 * Obtiene el timestamp actual en zona horaria RD
 */
function getRDTimestamp(): string {
    return date('Y-m-d H:i:s');
}

/**
 * Obtiene la fecha actual en RD
 */
function getRDDate(): string {
    return date('Y-m-d');
}

/**
 * Verifica si una fecha es válida y está dentro del rango del sistema
 */
function isValidExtractionDate(string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $ts = strtotime($date);
    if ($ts === false) return false;
    $start = strtotime(EXTRACTION_START);
    $today = strtotime(getRDDate());
    return $ts >= $start && $ts <= $today;
}

/**
 * Logger del sistema
 * @param string $message Mensaje a loguear
 * @param string $level   info | warning | error | debug | success
 * @param array  $context Datos adicionales (se serializan como JSON)
 */
function systemLog(string $message, string $level = 'info', array $context = []): void {
    if (!is_dir(LOGS_DIR)) {
        @mkdir(LOGS_DIR, 0755, true);
    }

    $timestamp = getRDTimestamp();
    $ctx       = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    $entry     = "[{$timestamp}] [" . strtoupper($level) . "] {$message}{$ctx}" . PHP_EOL;

    // Archivo de log por día
    $logFile = LOGS_DIR . 'system_' . date('Y-m-d') . '.log';
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

    // Log de errores separado
    if ($level === 'error') {
        $errFile = LOGS_DIR . 'errors.log';
        @file_put_contents($errFile, $entry, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Asegura que todos los directorios necesarios existan
 */
function ensureDirectories(): void {
    foreach ([LOGS_DIR, CACHE_DIR, TEMP_DIR] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
}

// Crear directorios al cargar el archivo
ensureDirectories();
