<?php
/**
 * Agente IA Autónomo - Lottery Scraper RD v4.0
 * DeepSeek (validación) + Perplexity Sonar-Pro (verificación contextual)
 */
require_once __DIR__ . '/config.php';

class AIAgent {
    private array $deepseekCfg;
    private array $perplexityCfg;
    private array $agentCfg;
    private array $learningData;
    private array $correctionPatterns;

    public function __construct() {
        global $DEEPSEEK_CONFIG, $PERPLEXITY_CONFIG, $AI_AGENT_CONFIG;
        $this->deepseekCfg   = $DEEPSEEK_CONFIG;
        $this->perplexityCfg = $PERPLEXITY_CONFIG;
        $this->agentCfg      = $AI_AGENT_CONFIG;
        $this->loadLearningData();
    }

    // ── API Pública ─────────────────────────────────────────────────

    /**
     * Valida un registro de sorteo con IA
     * Retorna: ['status' => valid|corrected|invalid|duplicate, ...]
     */
    public function validateRecord(array $record, int $index, array $allRecords = []): array {
        $this->learningData['total_validations']++;

        // Paso 1: Validación estructural básica (sin IA)
        $basic = $this->basicValidation($record);
        if (!$basic['is_valid']) {
            return $this->result('invalid', null, $basic['reasons']);
        }

        // Paso 2: Detectar duplicados en el lote actual
        if ($this->isDuplicate($record, $allRecords, $index)) {
            $this->learningData['duplicates_prevented']++;
            return $this->result('duplicate', null, ['Duplicado detectado en el lote']);
        }

        // Paso 3: Validación con DeepSeek (si está habilitado)
        if (!$this->deepseekCfg['enabled'] || empty($this->deepseekCfg['api_key']) || $this->deepseekCfg['api_key'] === 'TU_API_KEY_DEEPSEEK_AQUI') {
            // Sin API key → aceptar con confianza base
            return $this->result('valid', $record, ['IA desactivada - validación básica']);
        }

        $aiResult = $this->validateWithDeepSeek($record);

        // Paso 4: Verificación contextual con Perplexity si hay anomalías
        if ($aiResult['confidence'] < 0.8 && $aiResult['has_anomalies'] &&
            $this->agentCfg['context_verification_enabled'] &&
            $this->perplexityCfg['enabled'] &&
            !empty($this->perplexityCfg['api_key']) &&
            $this->perplexityCfg['api_key'] !== 'TU_API_KEY_PERPLEXITY_AQUI') {
            $ctx = $this->verifyWithPerplexity($record);
            if ($ctx['context_verified']) {
                $aiResult['confidence'] = min(1.0, $aiResult['confidence'] + 0.2);
            }
        }

        // Paso 5: Decidir acción según confianza
        $threshold = $this->agentCfg['confidence_threshold_accept'];
        $low       = $this->agentCfg['confidence_threshold_review'];

        if (!empty($aiResult['corrections_needed']) && $this->agentCfg['auto_correction_enabled']) {
            $corrected = $this->applyCorrections($record, $aiResult['corrections_needed']);
            return $this->result('corrected', $corrected, $aiResult['warnings'] ?? [], $aiResult['corrections_needed']);
        }

        if ($aiResult['confidence'] >= $threshold && !$aiResult['has_anomalies']) {
            return $this->result('valid', $record);
        } elseif ($aiResult['confidence'] >= $low) {
            return $this->result('needs_review', $record, $aiResult['warnings'] ?? []);
        }

        $this->learningData['errors_detected']++;
        return $this->result('invalid', null, $aiResult['reasons'] ?? ['Confianza muy baja: ' . $aiResult['confidence']]);
    }

    public function generatePerformanceReport(): array {
        $scores = $this->learningData['confidence_scores'] ?? [];
        $avg    = !empty($scores) ? array_sum($scores) / count($scores) : 0;
        return [
            'agent_status'       => $this->agentCfg['status'],
            'autonomy_level'     => $this->agentCfg['autonomy_level'],
            'total_validations'  => $this->learningData['total_validations'],
            'total_corrections'  => $this->learningData['total_corrections'],
            'errors_detected'    => $this->learningData['errors_detected'],
            'duplicates_prevented' => $this->learningData['duplicates_prevented'],
            'avg_confidence'     => round($avg, 3),
            'models'             => [
                'primary'   => $this->deepseekCfg['model'] . ' (DeepSeek)',
                'secondary' => $this->perplexityCfg['model'] . ' (Perplexity)',
            ],
            'timestamp'          => getRDTimestamp(),
        ];
    }

    // ── Validación ──────────────────────────────────────────────────

    private function basicValidation(array $r): array {
        $reasons = [];
        if (empty($r['lottery_name']))   $reasons[] = 'Falta nombre de lotería';
        if (empty($r['numbers']) || !is_array($r['numbers'])) {
            $reasons[] = 'Faltan números';
        } else {
            foreach ($r['numbers'] as $n) {
                if (!is_numeric($n) || $n < 0 || $n > 999) $reasons[] = "Número inválido: {$n}";
            }
        }
        if (empty($r['draw_date'])) $reasons[] = 'Falta fecha';
        return ['is_valid' => empty($reasons), 'reasons' => $reasons];
    }

    private function isDuplicate(array $record, array $all, int $idx): bool {
        foreach ($all as $i => $other) {
            if ($i === $idx) continue;
            if ($record['lottery_name'] === ($other['lottery_name'] ?? '') &&
                $record['numbers']      === ($other['numbers'] ?? []) &&
                $record['draw_date']    === ($other['draw_date'] ?? '')) {
                return true;
            }
        }
        return false;
    }

    private function validateWithDeepSeek(array $record): array {
        try {
            $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $prompt = "Analiza este registro de lotería dominicana y responde SOLO con JSON válido:\n```json\n{$json}\n```\n\nRespuesta esperada:\n{\"confidence\":0.95,\"is_valid\":true,\"has_anomalies\":false,\"anomalies_found\":[],\"warnings\":[],\"corrections_needed\":[],\"reasons_if_invalid\":[],\"suggestions\":[]}";

            $response = $this->callAPI($this->deepseekCfg['base_url'] . '/chat/completions', [
                'model'       => $this->deepseekCfg['model'],
                'messages'    => [
                    ['role' => 'system', 'content' => $this->agentCfg['system_prompt']],
                    ['role' => 'user',   'content' => $prompt],
                ],
                'temperature' => $this->deepseekCfg['temperature'],
                'max_tokens'  => $this->deepseekCfg['max_tokens'],
            ], 'Bearer ' . $this->deepseekCfg['api_key'], $this->deepseekCfg['timeout']);

            $content = $response['choices'][0]['message']['content'] ?? '{}';
            preg_match('/\{[\s\S]*\}/', $content, $m);
            $parsed = json_decode($m[0] ?? $content, true);

            if (!$parsed) throw new \Exception("JSON inválido en respuesta de DeepSeek");

            $conf = (float)($parsed['confidence'] ?? 0.5);
            $this->learningData['confidence_scores'][] = $conf;
            return [
                'confidence'          => $conf,
                'has_anomalies'       => (bool)($parsed['has_anomalies'] ?? false),
                'warnings'            => $parsed['warnings'] ?? [],
                'corrections_needed'  => $parsed['corrections_needed'] ?? [],
                'reasons'             => $parsed['reasons_if_invalid'] ?? [],
            ];
        } catch (\Exception $e) {
            systemLog("DeepSeek error: " . $e->getMessage(), 'warning');
            return ['confidence' => 0.6, 'has_anomalies' => false, 'warnings' => [], 'corrections_needed' => [], 'reasons' => []];
        }
    }

    private function verifyWithPerplexity(array $record): array {
        try {
            $name    = $record['lottery_name'] ?? 'Lotería';
            $date    = $record['draw_date'] ?? '';
            $numbers = implode(', ', $record['numbers'] ?? []);
            $query   = "¿Son correctos estos resultados de lotería dominicana? Lotería: {$name}, Fecha: {$date}, Números: {$numbers}. Busca en loteriasdominicanas.com o fuentes oficiales.";

            $response = $this->callAPI($this->perplexityCfg['base_url'] . '/chat/completions', [
                'model'       => $this->perplexityCfg['model'],
                'messages'    => [['role' => 'user', 'content' => $query]],
                'temperature' => $this->perplexityCfg['temperature'],
                'max_tokens'  => $this->perplexityCfg['max_tokens'],
            ], 'Bearer ' . $this->perplexityCfg['api_key'], $this->perplexityCfg['timeout']);

            $content = $response['choices'][0]['message']['content'] ?? '';
            $verified = stripos($content, 'correcto') !== false || stripos($content, 'confirm') !== false || stripos($content, 'válido') !== false;
            return ['context_verified' => $verified, 'content' => substr($content, 0, 300)];
        } catch (\Exception $e) {
            systemLog("Perplexity error: " . $e->getMessage(), 'warning');
            return ['context_verified' => false];
        }
    }

    // ── HTTP Helper ─────────────────────────────────────────────────

    private function callAPI(string $url, array $data, string $auth, int $timeout): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: ' . $auth],
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err)       throw new \RuntimeException("cURL: {$err}");
        if ($code < 200 || $code >= 300) throw new \RuntimeException("HTTP {$code}: " . substr($res, 0, 200));
        $decoded = json_decode($res, true);
        if (!$decoded)  throw new \RuntimeException("JSON inválido en respuesta API");
        return $decoded;
    }

    // ── Correcciones ────────────────────────────────────────────────

    private function applyCorrections(array $record, array $corrections): array {
        foreach ($corrections as $fix) {
            $field = $fix['field'] ?? null;
            $value = $fix['value'] ?? null;
            if ($field && $value !== null && isset($record[$field])) {
                $record[$field] = $value;
                $this->learningData['total_corrections']++;
            }
        }
        $record['_ai_corrected'] = true;
        return $record;
    }

    // ── Resultado estándar ──────────────────────────────────────────

    private function result(string $status, ?array $data, array $reasons = [], array $corrections = []): array {
        return [
            'status'         => $status,
            'corrected_data' => $data,
            'reasons'        => $reasons,
            'corrections'    => $corrections,
            'validated_by_ai'=> true,
            'timestamp'      => getRDTimestamp(),
        ];
    }

    // ── Persistencia de aprendizaje ─────────────────────────────────

    private function loadLearningData(): void {
        $file = CACHE_DIR . 'ai_learning_data.json';
        $defaults = ['total_validations' => 0, 'total_corrections' => 0, 'errors_detected' => 0,
                     'duplicates_prevented' => 0, 'confidence_scores' => []];
        $this->learningData       = file_exists($file) ? array_merge($defaults, json_decode(file_get_contents($file), true) ?? []) : $defaults;
        $pFile = CACHE_DIR . 'ai_patterns.json';
        $this->correctionPatterns = file_exists($pFile) ? (json_decode(file_get_contents($pFile), true) ?? []) : [];
    }

    public function __destruct() {
        // Mantener solo los últimos 200 scores para no crecer indefinidamente
        if (count($this->learningData['confidence_scores']) > 200) {
            $this->learningData['confidence_scores'] = array_slice($this->learningData['confidence_scores'], -200);
        }
        @file_put_contents(CACHE_DIR . 'ai_learning_data.json', json_encode($this->learningData, JSON_PRETTY_PRINT));
        @file_put_contents(CACHE_DIR . 'ai_patterns.json', json_encode($this->correctionPatterns, JSON_PRETTY_PRINT));
    }
}
