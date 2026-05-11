<?php
/**
 * ===================================================================
 * API REST - Lottery Scraper RD v4.0
 * ===================================================================
 *
 * Endpoints:
 *   GET  ?action=status         → Estado del scraper
 *   GET  ?action=results        → Últimos resultados
 *   GET  ?action=results&date=  → Resultados por fecha
 *   GET  ?action=stats          → Estadísticas globales
 *   GET  ?action=logs           → Logs recientes
 *   GET  ?action=progress       → Progreso histórico
 *   GET  ?action=companies      → Lista de empresas
 *   GET  ?action=firebase_test  → Test de conexión Firebase
 *   POST ?action=reset          → Reset completo (dev only)
 *   POST ?action=force_date     → Forzar fecha específica
 *   POST ?action=run_cron       → Ejecutar cron manualmente
 *   POST ?action=reset_firebase → Reset en Firebase
 * ===================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/scraper.php';
require_once __DIR__ . '/date_manager.php';
require_once __DIR__ . '/firebase_config.php';

// Headers CORS y JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

try {
    $db          = new LotteryDB();
    $dateManager = new DateManager($db);
    $firebase    = new FirebaseSync($db);

    switch ($action) {

        // ── Estado general ───────────────────────────────────────────
        case 'status':
            $state    = $db->getState();
            $progress = $dateManager->getProgress();
            $next     = $dateManager->getNextDateToProcess();

            echo json_encode([
                'success'    => true,
                'app_name'   => APP_NAME,
                'version'    => APP_VERSION,
                'env'        => APP_ENV,
                'timestamp'  => getRDTimestamp(),
                'timezone'   => 'America/Santo_Domingo',
                'state'      => $state,
                'progress'   => $progress,
                'next_date'  => $next,
                'total_results' => $db->countResults(),
                'companies_count' => count($db->getCompanies()),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // ── Resultados ───────────────────────────────────────────────
        case 'results':
            $date        = $_GET['date'] ?? null;
            $companySlug = $_GET['company'] ?? null;
            $limit       = min((int)($_GET['limit'] ?? 100), 500);

            function getLogoUrl($companySlug, $drawName) {
                if ($companySlug === 'leidsa' && $drawName === 'Loto Pool') return 'https://cdn-lottery.kiskoo.com/b9f95bbf6087f617f58efb5078ca5898.png';
                $filename = '';
                if ($companySlug === 'loto-real' && $drawName === 'Loto Pool') $filename = 'loto pool real';
                else if ($drawName === 'Juega + Pega +') $filename = 'juega-mas-pega-mas';
                else if ($drawName === 'Gana Más') $filename = 'gana-mas';
                else if ($drawName === 'Lotería Nacional') $filename = 'loteria-nacional';
                else if ($drawName === 'Pega 3 Más') $filename = 'pega-3-mas';
                else if ($drawName === 'Quiniela Leidsa') $filename = 'quiniela-leidsa';
                else if ($drawName === 'Super Kino TV') $filename = 'super-kino';
                else if ($drawName === 'Loto - Super Loto Más') $filename = 'loto-leidsa';
                else if ($drawName === 'Quiniela Real') $filename = 'loteria-real';
                else if ($drawName === 'Loto Real') $filename = 'loto-real';
                else if ($drawName === 'Loto Pool Noche') $filename = 'loto-pool-noche';
                else if ($drawName === 'Quiniela Loteka') $filename = 'quiniela-loteka';
                else if ($drawName === 'Mega Chances') $filename = 'mega-chances';
                else if ($drawName === 'MegaLotto') $filename = 'mega-lotto-loteka';
                else if ($drawName === 'Florida Día') $filename = 'florida-dia';
                else if ($drawName === 'Florida Noche') $filename = 'florida-noche';
                else if ($drawName === 'New York Tarde') $filename = 'new-york-tarde';
                else if ($drawName === 'New York Noche') $filename = 'new-york-noche';
                else if ($drawName === 'La Primera Día') $filename = 'la-primera-dia';
                else if ($drawName === 'Primera Noche') $filename = 'la-primera-noche';
                else if (strpos($drawName, 'La Suerte') !== false) $filename = 'la-suerte-dominicana';
                else if ($drawName === 'Quiniela LoteDom') $filename = 'quiniela-lotedom';
                else if ($drawName === 'El Quemaito Mayor') $filename = 'el-quemaito-mayor';
                else if (strpos($drawName, 'Anguila') !== false) $filename = 'anguila-lottery';
                else if (strpos($drawName, 'King Lottery') !== false) $filename = 'king-lottery';
                return $filename ? "https://cdn-lottery.kiskoo.com/loterias-dominicanas/{$filename}.png" : '';
            }

            if ($date) {
                $results = $db->getResultsByDateGrouped($date);
                $formattedDate = date('d/m/Y', strtotime($date));

                foreach ($results as $slug => &$comp) {
                    foreach ($comp['draws'] as &$d) {
                        $logoUrl = getLogoUrl($slug, $d['drawName']);
                        $logoHtml = $logoUrl ? "<img src='{$logoUrl}' style='max-width:60px; max-height:25px; object-fit:contain; float:right;' alt='logo'>" : "";
                        $d['drawTime'] = "<style>.draw-time { font-size: 0 !important; } .draw-time-content { font-size: 13px !important; color: #A0AEC0; }</style><div style='width:100%; display:inline-block; margin-bottom:8px;'><span class='draw-time-content'>📅 {$formattedDate}</span>{$logoHtml}</div>";
                        
                        $celestial = ['special1', 'special2', 'special3', 'normal', 'normal', 'normal', 'normal'];
                        $newTypes = [];
                        foreach ($d['numbers'] as $idx => $n) {
                            $newTypes[] = $celestial[$idx % count($celestial)];
                        }
                        $d['numberTypes'] = $newTypes;
                    }
                }
                unset($comp);

                echo json_encode([
                    'success' => true,
                    'date'    => $date,
                    'count'   => array_sum(array_map(fn($c) => count($c['draws']), $results)),
                    'data'    => $results,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                $results = $db->getResults($limit, null, $companySlug);
                echo json_encode([
                    'success' => true,
                    'count'   => count($results),
                    'data'    => $results,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            break;

        // ── Progreso histórico ───────────────────────────────────────
        case 'progress':
            echo json_encode([
                'success'  => true,
                'progress' => $dateManager->getProgress(),
                'pending'  => $dateManager->getPendingDates(20),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // ── Estadísticas ─────────────────────────────────────────────
        case 'stats':
            $state = $db->getState();
            echo json_encode([
                'success'    => true,
                'stats'      => [
                    'total_results'   => $db->countResults(),
                    'total_extracted' => $state['total_extracted'] ?? 0,
                    'total_saved'     => $state['total_saved'] ?? 0,
                    'total_errors'    => $state['total_errors'] ?? 0,
                    'duplicates_avoided' => $state['duplicates_avoided'] ?? 0,
                    'last_date'       => $state['last_processed_date'] ?? 'N/A',
                    'current_mode'    => $state['current_mode'] ?? 'historical',
                    'is_running'      => (bool)($state['is_running'] ?? false),
                    'is_paused'       => (bool)($state['is_paused'] ?? false),
                ],
                'companies'  => $db->getCompanies(),
                'timestamp'  => getRDTimestamp(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // ── Logs ─────────────────────────────────────────────────────
        case 'logs':
            $limit = min((int)($_GET['limit'] ?? 30), 200);
            echo json_encode([
                'success' => true,
                'logs'    => $db->getLogs($limit),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // ── Historial (Empresa o Sorteo) ─────────────────────────────
        case 'history':
            $companySlug = $_GET['company'] ?? null;
            $drawName    = $_GET['draw'] ?? null;
            $limit       = min((int)($_GET['limit'] ?? 10), 50);

            $data = $db->getHistory($limit, $companySlug, $drawName);
            
            foreach ($data as &$d) {
                $celestial = ['special1', 'special2', 'special3', 'normal', 'normal', 'normal', 'normal'];
                $newTypes = [];
                foreach ($d['numbers'] as $idx => $n) {
                    $newTypes[] = $celestial[$idx % count($celestial)];
                }
                $d['number_types'] = $newTypes;
            }
            unset($d);

            echo json_encode([
                'success' => true,
                'count'   => count($data),
                'data'    => $data,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // ── Empresas ─────────────────────────────────────────────────
        case 'companies':
            echo json_encode([
                'success'   => true,
                'companies' => $db->getCompanies(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // ── Test Firebase ────────────────────────────────────────────
        case 'firebase_test':
            echo json_encode([
                'success' => true,
                'result'  => $firebase->testConnection(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // ── Forzar fecha ─────────────────────────────────────────────
        case 'force_date':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Método no permitido']);
                break;
            }
            $forceDate = $_POST['date'] ?? $_GET['date'] ?? null;
            if (!$forceDate) {
                echo json_encode(['success' => false, 'error' => 'Parámetro date requerido']);
                break;
            }
            $result = $dateManager->forceDate($forceDate);
            echo json_encode($result, JSON_PRETTY_PRINT);
            break;

        // ── Ejecutar cron manualmente ────────────────────────────────
        case 'run_cron':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Método no permitido']);
                break;
            }
            $token = $_POST['token'] ?? $_GET['token'] ?? '';
            if ($token !== CRON_TOKEN) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Token inválido']);
                break;
            }
            // Redirigir al cronjob
            include __DIR__ . '/cronjob.php';
            break;

        // ── Reset Local (dev only) ───────────────────────────────────
        case 'reset':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Método no permitido']);
                break;
            }
            if (APP_ENV === 'production') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Reset no permitido en producción']);
                break;
            }
            $deleted = $db->resetAll();
            systemLog("⚠️ RESET COMPLETO ejecutado", 'warning');
            echo json_encode([
                'success'   => true,
                'message'   => 'Base de datos local reseteada',
                'deleted'   => $deleted,
                'timestamp' => getRDTimestamp(),
            ], JSON_PRETTY_PRINT);
            break;

        // ── Reset Firebase ────────────────────────────────────────────
        case 'reset_firebase':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Método no permitido']);
                break;
            }
            $result = $firebase->resetAll();
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // ── Default ───────────────────────────────────────────────────
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => "Acción desconocida: {$action}",
                'valid_actions' => ['status', 'results', 'stats', 'logs', 'progress', 'companies',
                                    'firebase_test', 'force_date', 'run_cron', 'reset', 'reset_firebase'],
            ]);
    }

} catch (\Exception $e) {
    http_response_code(500);
    systemLog("API Error: " . $e->getMessage(), 'error');
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'trace'   => APP_ENV !== 'production' ? $e->getTraceAsString() : null,
    ], JSON_PRETTY_PRINT);
}
