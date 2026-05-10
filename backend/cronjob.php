<?php
/**
 * ===================================================================
 * CRONJOB PRINCIPAL - Lottery Scraper RD v4.0
 * ===================================================================
 *
 * Este script es el punto de entrada del cronjob.
 * Ejecución: cada minuto vía tarea programada de Windows/Linux.
 *
 * Flujo:
 *   1. Verificar token de seguridad
 *   2. Verificar que no haya otra instancia corriendo
 *   3. Determinar la siguiente fecha a procesar (DateManager)
 *   4. Ejecutar el scraping (LotteryScraper)
 *   5. Validar con Agente IA (AIAgent)
 *   6. Guardar en SQLite local
 *   7. Sincronizar con Firebase (si está configurado)
 *   8. Marcar fecha como procesada
 *
 * URL de ejecución manual:
 *   http://localhost/resultados/cronjob.php?token=TU_TOKEN
 *
 * Configurar tarea en Windows (Task Scheduler):
 *   Programa: C:\xampp\php\php.exe
 *   Argumentos: C:\xampp\htdocs\resultados\cronjob.php
 *   Frecuencia: cada 1 minuto
 * ===================================================================
 */

// Evitar timeout del proceso
set_time_limit(120);
ignore_user_abort(true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/scraper.php';
require_once __DIR__ . '/date_manager.php';
require_once __DIR__ . '/ai_agent.php';
require_once __DIR__ . '/firebase_config.php';
require_once __DIR__ . '/git_sync.php';

// --- AUTO-UPDATE SILENCIOSO ---
// Revisa si hay cambios en GitHub antes de proceder con el scraper
syncWithGithub();


// ── SALIDA: JSON siempre ──────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

$startTime = microtime(true);

// ── 1. SEGURIDAD: Verificar token ────────────────────────────────
// En CLI (ejecución desde consola/task scheduler) no se requiere token
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    if ($token !== CRON_TOKEN) {
        http_response_code(403);
        die(json_encode(['success' => false, 'error' => 'Token inválido']));
    }
}

// ── 2. INSTANCIA ÚNICA: Prevenir ejecuciones simultáneas ─────────
$lockFile = TEMP_DIR . 'scraper.lock';
$lockFd   = fopen($lockFile, 'w');
if (!flock($lockFd, LOCK_EX | LOCK_NB)) {
    fclose($lockFd);
    die(json_encode([
        'success' => false,
        'message' => 'Otra instancia del scraper está corriendo',
        'status'  => 'locked'
    ]));
}

// Escribir PID en el lock
fwrite($lockFd, getmypid() . "\n" . getRDTimestamp());

try {
    // ── 3. INICIALIZAR COMPONENTES ────────────────────────────────
    $db          = new LotteryDB();
    $dateManager = new DateManager($db);
    $scraper     = new LotteryScraper();
    $aiAgent     = new AIAgent();
    $firebase    = new FirebaseSync($db);

    // ── 4. DETERMINAR FECHA A PROCESAR ───────────────────────────
    $nextInfo      = $dateManager->getNextDateToProcess();
    $dateToProcess = $nextInfo['date'];
    $mode          = $nextInfo['mode'];

    // ⚡ Modo tiempo real: SIEMPRE procesar hoy aunque ya se haya procesado hoy,
    // porque los sorteos salen en diferentes horarios durante el día.
    // En modo histórico, la lógica normal avanza una fecha por ejecución.
    if ($mode === 'realtime') {
        $dateToProcess = date('Y-m-d', strtotime('now'));  // Forzar fecha actual en RD
    }

    systemLog("=== CRON INICIO === Fecha: {$dateToProcess} | Modo: {$mode}");

    // Marcar como corriendo
    $db->updateState(['is_running' => 1]);

    // ── 5. SCRAPING ───────────────────────────────────────────────
    $scrapeResult = $scraper->scrape($dateToProcess, null);  // Sin $db para validar antes

    if (empty($scrapeResult['results'])) {
        // No hay resultados → saltar esta fecha
        $dateManager->markDateAsProcessed($dateToProcess);
        $db->addLog($dateToProcess, 'cron', 'info', 'Sin resultados para esta fecha (día festivo o sin sorteos)');
        $db->updateState(['is_running' => 0]);

        outputResult([
            'success' => true,
            'date'    => $dateToProcess,
            'mode'    => $mode,
            'message' => 'Sin resultados para esta fecha',
            'stats'   => $scrapeResult['stats'],
            'progress' => $dateManager->getProgress(),
        ]);
        exit;
    }

    // ── 6. VALIDACIÓN CON AGENTE IA ───────────────────────────────
    $validatedResults = [];
    $aiStats = ['validated' => 0, 'corrected' => 0, 'rejected' => 0, 'needs_review' => 0];

    foreach ($scrapeResult['results'] as $i => $record) {
        // Adaptar formato para el agente IA
        $aiRecord = [
            'lottery_name'   => $record['drawName'],
            'company'        => $record['companyName'],
            'draw_date'      => $record['drawDate'],
            'draw_time'      => $record['drawTime'],
            'numbers'        => $record['numbers'],
            'number_types'   => $record['numberTypes'],
            'source_url'     => $record['sourceUrl'],
        ];

        $validation = $aiAgent->validateRecord($aiRecord, $i, array_map(fn($r) => [
            'lottery_name' => $r['drawName'],
            'numbers'      => $r['numbers'],
            'draw_date'    => $r['drawDate'],
        ], $scrapeResult['results']));

        $aiStats['validated']++;

        switch ($validation['status']) {
            case 'valid':
            case 'needs_review':
                $validatedResults[] = $record;
                if ($validation['status'] === 'needs_review') $aiStats['needs_review']++;
                break;

            case 'corrected':
                // Aplicar correcciones del agente al registro
                if (isset($validation['corrected_data'])) {
                    $corrected = $record;
                    $cd = $validation['corrected_data'];
                    if (isset($cd['lottery_name']))  $corrected['drawName'] = $cd['lottery_name'];
                    if (isset($cd['numbers']))        $corrected['numbers']  = $cd['numbers'];
                    $corrected['_ai_corrected'] = true;
                    $validatedResults[] = $corrected;
                } else {
                    $validatedResults[] = $record;
                }
                $aiStats['corrected']++;
                break;

            case 'invalid':
            case 'duplicate':
                $aiStats['rejected']++;
                systemLog("Registro rechazado por IA: {$record['drawName']} ({$dateToProcess}) - " . implode(', ', $validation['reasons'] ?? []), 'warning');
                break;
        }
    }

    // ── 7. GUARDAR EN BASE DE DATOS ───────────────────────────────
    $saved = $updated = $duplicates = $dbErrors = 0;

    foreach ($validatedResults as $r) {
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
            $dbErrors++;
            systemLog("Error DB: " . $e->getMessage(), 'error');
        }
    }

    // ── 8. SINCRONIZAR CON FIREBASE ───────────────────────────────
    $firebaseResult = ['synced' => 0, 'errors' => 0];
    if ($saved > 0 || $updated > 0) {
        try {
            $firebaseResult = $firebase->syncDate($dateToProcess);
        } catch (\Exception $e) {
            systemLog("Error Firebase sync: " . $e->getMessage(), 'warning');
        }
    }

    // ── 9. ACTUALIZAR ESTADO Y MARCAR FECHA ──────────────────────
    $db->updateState([
        'is_running'       => 0,
        'total_extracted'  => '(total_extracted + ' . count($scrapeResult['results']) . ')',
        'total_saved'      => '(total_saved + ' . $saved . ')',
        'total_errors'     => '(total_errors + ' . $dbErrors . ')',
        'duplicates_avoided' => '(duplicates_avoided + ' . ($duplicates + $aiStats['rejected']) . ')',
    ]);

    $dateManager->markDateAsProcessed($dateToProcess);

    // Log de la ejecución
    $logMsg = "CRON: {$dateToProcess} | Extraídos: " . count($scrapeResult['results']) .
              " | IA-validados: {$aiStats['validated']} | Guardados: {$saved} | " .
              "Actualizados: {$updated} | Rechazados: {$aiStats['rejected']} | Firebase: {$firebaseResult['synced']}";

    $db->addLog(
        $dateToProcess,
        'cron',
        $dbErrors > 0 ? 'warning' : 'success',
        $logMsg,
        json_encode(array_merge($aiStats, ['firebase' => $firebaseResult])),
        (int)((microtime(true) - $startTime) * 1000)
    );

    systemLog("=== CRON FIN === " . $logMsg);

    // ── 10. RESPUESTA ─────────────────────────────────────────────
    outputResult([
        'success'        => true,
        'date'           => $dateToProcess,
        'mode'           => $mode,
        'message'        => $logMsg,
        'stats' => [
            'extracted'  => count($scrapeResult['results']),
            'validated'  => $aiStats['validated'],
            'corrected'  => $aiStats['corrected'],
            'rejected'   => $aiStats['rejected'],
            'saved'      => $saved,
            'updated'    => $updated,
            'duplicates' => $duplicates,
            'db_errors'  => $dbErrors,
            'firebase'   => $firebaseResult,
            'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
        ],
        'progress'       => $dateManager->getProgress(),
        'ai_report'      => $aiAgent->generatePerformanceReport(),
        'next_date'      => $dateManager->getNextDateToProcess(),
    ]);

} catch (\Exception $e) {
    systemLog("CRON ERROR CRÍTICO: " . $e->getMessage(), 'error');
    if (isset($db)) {
        $db->updateState(['is_running' => 0]);
        $db->addLog(
            $dateToProcess ?? getRDDate(),
            'cron',
            'error',
            'Error crítico: ' . $e->getMessage()
        );
    }
    outputResult([
        'success' => false,
        'error'   => $e->getMessage(),
        'date'    => $dateToProcess ?? null,
    ]);

} finally {
    // Siempre liberar el lock
    if (isset($lockFd) && is_resource($lockFd)) {
        flock($lockFd, LOCK_UN);
        fclose($lockFd);
        @unlink($lockFile);
    }
}

// ─── Función auxiliar ────────────────────────────────────────────

function outputResult(array $data): void {
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
