<?php
/**
 * ===================================================================
 * BASE DE DATOS - Lottery Scraper RD v4.1
 * ===================================================================
 * MySQL via PDO para alojamiento compartido (x10hosting)
 * ===================================================================
 */

require_once __DIR__ . '/config.php';

class LotteryDB {
    private PDO $db;

    public function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $this->db = new PDO($dsn, DB_USER, DB_PASS, $options);
            $this->createTables();
        } catch (PDOException $e) {
            systemLog("Error de conexión MySQL: " . $e->getMessage(), 'error');
            
            // En lugar de arrojar una excepción (que causa Error 500), mostramos el error claro
            http_response_code(503);
            die("
                <div style='font-family: sans-serif; background: #2b2b2b; color: white; padding: 20px; border-radius: 8px; margin: 50px auto; max-width: 600px; text-align: center;'>
                    <h2 style='color: #ff6b6b;'>⚠️ Error de Conexión a la Base de Datos</h2>
                    <p>No se pudo conectar a MySQL. Por favor verifica lo siguiente en x10hosting:</p>
                    <ul style='text-align: left;'>
                        <li>Que las credenciales en <b>config.php</b> sean correctas.</li>
                        <li><b>MUY IMPORTANTE:</b> Que hayas asignado el usuario a la base de datos con 'Todos los Privilegios' (Add User To Database).</li>
                    </ul>
                    <p style='background: #1e1e1e; padding: 10px; border-radius: 4px; font-family: monospace; color: #ff9e9e;'>
                        Detalle del error: " . htmlspecialchars($e->getMessage()) . "
                    </p>
                </div>
            ");
        }
    }

    // ─── Schema ──────────────────────────────────────────────────────

    private function createTables(): void {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS companies (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                name       VARCHAR(100) NOT NULL UNIQUE,
                slug       VARCHAR(100) NOT NULL UNIQUE,
                color      VARCHAR(20) DEFAULT "#667eea",
                block_id   INT NOT NULL UNIQUE,
                country    VARCHAR(10) DEFAULT "RD",
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS draws (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                company_id   INT NOT NULL,
                draw_name    VARCHAR(150) NOT NULL,
                draw_slug    VARCHAR(150) NOT NULL,
                draw_date    DATE NOT NULL,
                draw_time    VARCHAR(50),
                numbers      JSON NOT NULL,
                number_types JSON,
                source_url   VARCHAR(255),
                is_past      TINYINT(1) DEFAULT 1,
                ai_validated TINYINT(1) DEFAULT 0,
                ai_corrected TINYINT(1) DEFAULT 0,
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
                UNIQUE KEY unique_draw (draw_slug, draw_date),
                INDEX idx_draw_date (draw_date),
                INDEX idx_company (company_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS scraping_state (
                id                  VARCHAR(20) PRIMARY KEY DEFAULT "main",
                last_processed_date DATE NULL,
                current_mode        VARCHAR(20) DEFAULT "historical",
                total_extracted     INT DEFAULT 0,
                total_saved         INT DEFAULT 0,
                total_errors        INT DEFAULT 0,
                duplicates_avoided  INT DEFAULT 0,
                last_execution_at   DATETIME NULL,
                next_date_to_process DATE NULL,
                start_date          DATE DEFAULT "2012-01-01",
                is_running          TINYINT(1) DEFAULT 0,
                is_paused           TINYINT(1) DEFAULT 0,
                created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS scraping_logs (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                date       DATE NOT NULL,
                action     VARCHAR(50) NOT NULL,
                status     VARCHAR(50) NOT NULL,
                message    TEXT NOT NULL,
                details    JSON NULL,
                duration   INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_logs_date (date),
                INDEX idx_logs_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        // Inicializar estado
        $stmt = $this->db->query("SELECT COUNT(*) FROM scraping_state WHERE id = 'main'");
        if ($stmt->fetchColumn() == 0) {
            $this->db->exec("INSERT INTO scraping_state (id) VALUES ('main')");
        }

        $this->seedCompanies();
    }

    private function seedCompanies(): void {
        $stmt = $this->db->query("SELECT COUNT(*) FROM companies");
        if ($stmt->fetchColumn() > 0) return;

        $stmtInsert = $this->db->prepare("
            INSERT IGNORE INTO companies (name, slug, color, block_id, country)
            VALUES (:name, :slug, :color, :block_id, :country)
        ");

        foreach (getCompanyMap() as $blockId => $info) {
            $stmtInsert->execute([
                ':name'     => $info['name'],
                ':slug'     => $info['slug'],
                ':color'    => $info['color'] ?? '#667eea',
                ':block_id' => intval($blockId),
                ':country'  => $info['country'] ?? 'RD'
            ]);
        }
    }

    // ─── Estado ──────────────────────────────────────────────────────

    public function getState(): array {
        $stmt = $this->db->query("SELECT * FROM scraping_state WHERE id = 'main'");
        return $stmt->fetch() ?: [];
    }

    public function updateState(array $data): void {
        $sets = [];
        $params = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                $sets[] = "`$key` = NULL";
            } elseif (is_string($value) && preg_match('/^\(.+\)$/', $value)) {
                // Para expresiones como (total_extracted + 1)
                $expr = trim($value, '()');
                $sets[] = "`$key` = $expr";
            } else {
                $sets[] = "`$key` = :$key";
                $params[":$key"] = $value;
            }
        }
        if (empty($sets)) return;
        
        $sql = "UPDATE scraping_state SET " . implode(', ', $sets) . " WHERE id = 'main'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    // ─── Resultados ──────────────────────────────────────────────────

    public function getResults(int $limit = 50, ?string $date = null, ?string $companySlug = null): array {
        $sql = "SELECT d.*, c.name as company_name, c.slug as company_slug,
                       c.color as company_color, c.block_id as company_block_id
                FROM draws d JOIN companies c ON d.company_id = c.id";
        
        $where = [];
        $params = [];
        if ($date) {
            $where[] = "d.draw_date = :date";
            $params[':date'] = $date;
        }
        if ($companySlug) {
            $where[] = "c.slug = :company_slug";
            $params[':company_slug'] = $companySlug;
        }
        
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        
        // PDO no permite bindParam en LIMIT directamente de forma sencilla si no se tipa explícitamente, 
        // lo forzamos a entero en la consulta.
        $limit = intval($limit);
        $sql .= " ORDER BY d.draw_date DESC, c.block_id ASC LIMIT $limit";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $results = [];
        while ($row = $stmt->fetch()) {
            $results[] = $this->hydrateRow($row);
        }
        return $results;
    }

    public function getResultsByDateGrouped(string $date): array {
        $sql = "SELECT d.*, c.name as company_name, c.slug as company_slug,
                       c.color as company_color, c.block_id as company_block_id
                FROM draws d JOIN companies c ON d.company_id = c.id
                WHERE d.draw_date = :date
                ORDER BY c.block_id ASC, d.draw_name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':date' => $date]);
        
        $results = [];
        while ($row = $stmt->fetch()) {
            $slug = $row['company_slug'];
            if (!isset($results[$slug])) {
                $results[$slug] = [
                    'company' => [
                        'name'    => $row['company_name'],
                        'slug'    => $slug,
                        'color'   => $row['company_color'],
                        'blockId' => $row['company_block_id'],
                    ],
                    'draws' => [],
                ];
            }
            $results[$slug]['draws'][] = [
                'drawName'    => $row['draw_name'],
                'drawTime'    => $row['draw_time'],
                'numbers'     => json_decode($row['numbers'], true),
                'numberTypes' => $row['number_types'] ? json_decode($row['number_types'], true) : null,
                'aiValidated' => (bool)$row['ai_validated'],
                'aiCorrected' => (bool)$row['ai_corrected'],
            ];
        }
        return $results;
    }

    /**
     * Obtiene el historial de un sorteo específico o de una empresa (últimos 10)
     */
    public function getHistory(int $limit = 10, ?string $companySlug = null, ?string $drawName = null): array {
        $sql = "SELECT d.*, c.name as company_name, c.slug as company_slug,
                       c.color as company_color
                FROM draws d 
                JOIN companies c ON d.company_id = c.id ";
        
        $where = [];
        $params = [];
        
        if ($companySlug) {
            $where[] = "c.slug = :company_slug";
            $params[':company_slug'] = $companySlug;
        }
        if ($drawName) {
            $where[] = "d.draw_name = :draw_name";
            $params[':draw_name'] = $drawName;
        }
        
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        
        $sql .= " ORDER BY d.draw_date DESC LIMIT " . intval($limit);
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $results = [];
        while ($row = $stmt->fetch()) {
            $results[] = $this->hydrateRow($row);
        }
        return $results;
    }

    public function countResults(?string $date = null): int {
        if ($date) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM draws WHERE draw_date = :date");
            $stmt->execute([':date' => $date]);
            return (int)$stmt->fetchColumn();
        }
        return (int)$this->db->query("SELECT COUNT(*) FROM draws")->fetchColumn();
    }

    // ─── Guardar sorteo ──────────────────────────────────────────────

    public function saveDraw(int $companyId, string $drawName, string $drawSlug,
                              string $drawDate, ?string $drawTime, array $numbers,
                              ?array $numberTypes, ?string $sourceUrl, bool $isPast,
                              bool $aiValidated = false, bool $aiCorrected = false): string {
        
        $nJson = json_encode($numbers);
        $tJson = $numberTypes ? json_encode($numberTypes) : null;

        $stmtCheck = $this->db->prepare("SELECT id, numbers FROM draws WHERE draw_slug = :slug AND draw_date = :date");
        $stmtCheck->execute([':slug' => $drawSlug, ':date' => $drawDate]);
        $existing = $stmtCheck->fetch();

        if ($existing) {
            if ($existing['numbers'] !== $nJson) {
                $stmtUpdate = $this->db->prepare("
                    UPDATE draws SET
                        numbers = :numbers,
                        number_types = :number_types,
                        draw_time = :draw_time,
                        is_past = :is_past,
                        source_url = :source_url,
                        ai_validated = :ai_val,
                        ai_corrected = :ai_cor
                    WHERE id = :id
                ");
                $stmtUpdate->execute([
                    ':numbers'      => $nJson,
                    ':number_types' => $tJson,
                    ':draw_time'    => $drawTime,
                    ':is_past'      => intval($isPast),
                    ':source_url'   => $sourceUrl,
                    ':ai_val'       => intval($aiValidated),
                    ':ai_cor'       => intval($aiCorrected),
                    ':id'           => $existing['id']
                ]);
                return 'updated';
            }
            return 'duplicate';
        }

        $stmtInsert = $this->db->prepare("
            INSERT INTO draws
                (company_id, draw_name, draw_slug, draw_date, draw_time, numbers, number_types, source_url, is_past, ai_validated, ai_corrected)
            VALUES (
                :comp, :name, :slug, :date, :time, :nums, :types, :url, :past, :val, :cor
            )
        ");
        $stmtInsert->execute([
            ':comp' => $companyId,
            ':name' => $drawName,
            ':slug' => $drawSlug,
            ':date' => $drawDate,
            ':time' => $drawTime,
            ':nums' => $nJson,
            ':types'=> $tJson,
            ':url'  => $sourceUrl,
            ':past' => intval($isPast),
            ':val'  => intval($aiValidated),
            ':cor'  => intval($aiCorrected)
        ]);
        
        return 'saved';
    }

    // ─── Empresas ────────────────────────────────────────────────────

    public function getCompanies(): array {
        $sql = "SELECT c.*, COUNT(d.id) as draws_count FROM companies c
                LEFT JOIN draws d ON c.id = d.company_id
                GROUP BY c.id ORDER BY c.block_id ASC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll() ?: [];
    }

    public function ensureCompany(string $name, string $slug, string $color, int $blockId): array {
        $stmtCheck = $this->db->prepare("SELECT * FROM companies WHERE block_id = :block_id");
        $stmtCheck->execute([':block_id' => $blockId]);
        $existing = $stmtCheck->fetch();
        
        if ($existing) return $existing;

        $stmtInsert = $this->db->prepare("
            INSERT IGNORE INTO companies (name, slug, color, block_id)
            VALUES (:name, :slug, :color, :block_id)
        ");
        $stmtInsert->execute([
            ':name'     => $name,
            ':slug'     => $slug,
            ':color'    => $color,
            ':block_id' => $blockId
        ]);
        
        $stmtCheck->execute([':block_id' => $blockId]);
        return $stmtCheck->fetch() ?: [];
    }

    // ─── Logs ────────────────────────────────────────────────────────

    public function getLogs(int $limit = 30): array {
        $stmt = $this->db->prepare("SELECT * FROM scraping_logs ORDER BY created_at DESC LIMIT " . intval($limit));
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public function addLog(string $date, string $action, string $status, string $message,
                            ?string $details = null, int $duration = 0): void {
        $stmt = $this->db->prepare("
            INSERT INTO scraping_logs (date, action, status, message, details, duration)
            VALUES (:date, :action, :status, :msg, :det, :dur)
        ");
        // Asegurar que 'details' es un JSON válido si no es null
        $detJson = $details ? (json_decode($details) ? $details : json_encode(['raw' => $details])) : null;
        
        $stmt->execute([
            ':date'   => $date,
            ':action' => $action,
            ':status' => $status,
            ':msg'    => $message,
            ':det'    => $detJson,
            ':dur'    => $duration
        ]);
    }

    // ─── Reset ───────────────────────────────────────────────────────

    public function resetAll(): array {
        $drawCount = $this->db->query("SELECT COUNT(*) FROM draws")->fetchColumn();
        $logCount  = $this->db->query("SELECT COUNT(*) FROM scraping_logs")->fetchColumn();
        
        $this->db->exec("TRUNCATE TABLE draws");
        $this->db->exec("TRUNCATE TABLE scraping_logs");
        $this->db->exec("
            UPDATE scraping_state SET
                last_processed_date = NULL,
                total_extracted = 0, total_saved = 0, total_errors = 0,
                duplicates_avoided = 0, last_execution_at = NULL,
                next_date_to_process = NULL, is_running = 0, is_paused = 0
            WHERE id = 'main'
        ");
        return ['draws' => $drawCount, 'logs' => $logCount];
    }

    // ─── Helpers privados ────────────────────────────────────────────

    private function hydrateRow(array $row): array {
        $row['numbers']      = json_decode($row['numbers'], true);
        $row['number_types'] = $row['number_types'] ? json_decode($row['number_types'], true) : null;
        $row['is_past']      = (bool)$row['is_past'];
        $row['ai_validated'] = (bool)$row['ai_validated'];
        $row['ai_corrected'] = (bool)$row['ai_corrected'];
        $row['company']      = [
            'name'    => $row['company_name'],
            'slug'    => $row['company_slug'],
            'color'   => $row['company_color'],
            'blockId' => $row['company_block_id'],
        ];
        unset($row['company_name'], $row['company_slug'], $row['company_color'],
              $row['company_block_id'], $row['company_id']);
        return $row;
    }
}
