<?php
/**
 * ===================================================================
 * SINCRONIZACIÓN FIREBASE - Lottery Scraper RD v4.0
 * ===================================================================
 *
 * Sube los resultados de lotería a Firebase Realtime Database.
 * Permite resetear datos en fase de prueba y tener una BD limpia
 * para producción.
 *
 * Configuración:
 *   1. Ve a Firebase Console → Tu Proyecto → Configuración → Cuentas de servicio
 *   2. Genera una nueva clave privada (archivo JSON)
 *   3. Guárdala como "firebase-service-account.json" en la raíz del proyecto
 *   4. Activa $FIREBASE_CONFIG['enabled'] = true en config.php
 *
 * Estructura en Firebase:
 *   lottery_draws/
 *     {YYYY-MM-DD}/
 *       {company_slug}/
 *         {draw_slug}/
 *           drawName: string
 *           drawTime: string
 *           numbers: array
 *           ...
 *   lottery_stats/
 *     total_draws: number
 *     last_updated: timestamp
 *     last_date: string
 * ===================================================================
 */

require_once __DIR__ . '/config.php';

class FirebaseSync {

    private $db;
    private bool   $enabled;
    private string $projectId;
    private string $databaseUrl;
    private string $credentialsFile;
    private ?string $accessToken = null;

    public function __construct($db) {
        global $FIREBASE_CONFIG;
        $this->db              = $db;
        $this->enabled         = $FIREBASE_CONFIG['enabled'] ?? false;
        $this->projectId       = $FIREBASE_CONFIG['project_id'] ?? '';
        $this->databaseUrl     = rtrim($FIREBASE_CONFIG['database_url'] ?? '', '/');
        $this->credentialsFile = $FIREBASE_CONFIG['credentials_file'] ?? '';
    }

    // ─── API Pública ─────────────────────────────────────────────────

    /**
     * Sincroniza todos los resultados de una fecha con Firebase
     */
    public function syncDate(string $date): array {
        if (!$this->enabled) {
            return ['synced' => 0, 'errors' => 0, 'message' => 'Firebase desactivado'];
        }

        try {
            $results = $this->db->getResultsByDateGrouped($date);
            if (empty($results)) {
                return ['synced' => 0, 'errors' => 0, 'message' => 'Sin datos para sincronizar'];
            }

            $token   = $this->getAccessToken();
            $synced  = 0;
            $errors  = 0;

            foreach ($results as $companySlug => $companyData) {
                foreach ($companyData['draws'] as $draw) {
                    try {
                        $path = "lottery_draws/{$date}/{$companySlug}/" . slugifyDrawName($draw['drawName']);
                        $data = [
                            'date'        => $date,
                            'companyName' => $companyData['company']['name'],
                            'companySlug' => $companySlug,
                            'companyColor'=> $companyData['company']['color'],
                            'drawName'    => $draw['drawName'],
                            'drawTime'    => $draw['drawTime'],
                            'numbers'     => $draw['numbers'],
                            'numberTypes' => $draw['numberTypes'],
                            'syncedAt'    => getRDTimestamp(),
                        ];

                        $this->putFirebase($path, $data, $token);
                        $synced++;

                    } catch (\Exception $e) {
                        $errors++;
                        systemLog("Firebase sync error ({$date}/{$companySlug}): " . $e->getMessage(), 'warning');
                    }
                }
            }

            // Actualizar estadísticas globales en Firebase
            $this->updateStats($token);

            systemLog("Firebase sync: {$date} → {$synced} registros sincronizados", 'info');
            return ['synced' => $synced, 'errors' => $errors, 'message' => "Sincronizados {$synced} sorteos"];

        } catch (\Exception $e) {
            systemLog("Firebase sync error crítico: " . $e->getMessage(), 'error');
            return ['synced' => 0, 'errors' => 1, 'error' => $e->getMessage()];
        }
    }

    /**
     * RESET COMPLETO: Borra todos los datos de Firebase
     * ⚠️ USAR SOLO EN FASE DE PRUEBA
     */
    public function resetAll(): array {
        if (!$this->enabled) {
            return ['success' => false, 'message' => 'Firebase desactivado'];
        }

        try {
            $token = $this->getAccessToken();

            // Borrar colecciones
            $this->deleteFirebase('lottery_draws', $token);
            $this->deleteFirebase('lottery_stats', $token);
            $this->deleteFirebase('lottery_companies', $token);

            systemLog("🔴 FIREBASE RESET COMPLETO ejecutado", 'warning');
            return [
                'success' => true,
                'message' => 'Firebase reseteado correctamente. Todos los datos eliminados.',
                'timestamp' => getRDTimestamp(),
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verifica el estado de la conexión Firebase
     */
    public function testConnection(): array {
        if (!$this->enabled) {
            return ['connected' => false, 'message' => 'Firebase no está configurado/activado'];
        }

        if (!file_exists($this->credentialsFile)) {
            return [
                'connected' => false,
                'message'   => 'Archivo de credenciales no encontrado: ' . $this->credentialsFile,
            ];
        }

        try {
            $token  = $this->getAccessToken();
            $result = $this->getFirebase('lottery_stats/last_ping', $token);
            $this->putFirebase('lottery_stats/last_ping', ['time' => getRDTimestamp()], $token);
            return [
                'connected' => true,
                'project'   => $this->projectId,
                'database'  => $this->databaseUrl,
                'message'   => 'Conexión Firebase OK',
            ];
        } catch (\Exception $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Sube las empresas a Firebase
     */
    public function syncCompanies(): array {
        if (!$this->enabled) return ['synced' => 0];

        try {
            $token     = $this->getAccessToken();
            $companies = $this->db->getCompanies();
            $synced    = 0;

            foreach ($companies as $company) {
                $this->putFirebase("lottery_companies/{$company['slug']}", [
                    'name'       => $company['name'],
                    'slug'       => $company['slug'],
                    'color'      => $company['color'],
                    'drawsCount' => $company['draws_count'],
                    'updatedAt'  => getRDTimestamp(),
                ], $token);
                $synced++;
            }

            return ['synced' => $synced];
        } catch (\Exception $e) {
            return ['synced' => 0, 'error' => $e->getMessage()];
        }
    }

    // ─── Firebase REST API ───────────────────────────────────────────

    /**
     * Obtiene el access token de Google OAuth2 para la API REST de Firebase
     * usando las credenciales de service account
     */
    private function getAccessToken(): string {
        if ($this->accessToken) return $this->accessToken;

        if (!file_exists($this->credentialsFile)) {
            throw new \RuntimeException("Credenciales Firebase no encontradas: {$this->credentialsFile}");
        }

        $creds = json_decode(file_get_contents($this->credentialsFile), true);
        if (!$creds || !isset($creds['private_key'])) {
            throw new \RuntimeException("Archivo de credenciales Firebase inválido");
        }

        // Crear JWT para obtener token OAuth2
        $now = time();
        $header  = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode([
            'iss'   => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.database https://www.googleapis.com/auth/userinfo.email',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ]));

        $signingInput = $header . '.' . $payload;
        openssl_sign($signingInput, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = $signingInput . '.' . base64_encode($signature);

        // Intercambiar JWT por access token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]);
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (empty($response['access_token'])) {
            throw new \RuntimeException("No se pudo obtener token Firebase: " . json_encode($response));
        }

        $this->accessToken = $response['access_token'];
        return $this->accessToken;
    }

    private function putFirebase(string $path, array $data, string $token): void {
        $url = "{$this->databaseUrl}/{$path}.json?access_token={$token}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("Firebase PUT error {$code}: {$res}");
        }
    }

    private function getFirebase(string $path, string $token): mixed {
        $url = "{$this->databaseUrl}/{$path}.json?access_token={$token}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res  = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true);
    }

    private function deleteFirebase(string $path, string $token): void {
        $url = "{$this->databaseUrl}/{$path}.json?access_token={$token}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function updateStats(string $token): void {
        $state = $this->db->getState();
        $this->putFirebase('lottery_stats', [
            'totalDraws'   => $this->db->countResults(),
            'lastDate'     => $state['last_processed_date'] ?? '',
            'lastUpdated'  => getRDTimestamp(),
            'currentMode'  => $state['current_mode'] ?? 'historical',
            'totalSaved'   => $state['total_saved'] ?? 0,
        ], $token);
    }
}
