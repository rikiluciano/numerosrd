<?php
/**
 * ===================================================================
 * GIT SYNC - Sistema de Auto-Actualización Silenciosa
 * ===================================================================
 */

// --- EJECUCIÓN DIRECTA (Para Cron o Debug) ---
if (php_sapi_name() === 'cli' || isset($_GET['debug'])) {
    $result = syncWithGithub();
    if (isset($_GET['debug'])) {
        echo $result ? "✅ Actualización completada con éxito." : "❌ La actualización falló o no hay cambios nuevos.";
    }
}

function syncWithGithub() {
    // --- CONFIGURACIÓN ---
    $repoUser = "rikiluciano"; 
    $repoName = "numerosrd";    
    $branch   = "main";
    $versionFile = __DIR__ . '/version.json';
    
    if (isset($_GET['debug'])) echo "🔍 Iniciando sincronización con $repoUser/$repoName...<br>";

    // 1. Obtener última versión (commit) de GitHub via API
    $token = "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"; 
    $opts = [
        "http" => [
            "method" => "GET",
            "header" => [
                "User-Agent: PHP-AutoUpdate",
                "Authorization: token $token"
            ]
        ]
    ];
    $context = stream_context_create($opts);
    $apiUrl = "https://api.github.com/repos/$repoUser/$repoName/commits/$branch";
    
    try {
        $response = @file_get_contents($apiUrl, false, $context);
        if (!$response) {
            if (isset($_GET['debug'])) echo "❌ Error: No se pudo conectar con la API de GitHub.<br>";
            return false;
        }
        
        $githubData = json_decode($response, true);
        $latestCommit = $githubData['sha'];
        
        if (isset($_GET['debug'])) echo "📦 Último commit en GitHub: $latestCommit<br>";

        // 2. Leer versión local
        $localData = file_exists($versionFile) ? json_decode(file_get_contents($versionFile), true) : ['commit' => ''];
        
        if (isset($_GET['debug'])) echo "🏠 Versión local: " . ($localData['commit'] ?: 'Ninguna') . "<br>";

        // 3. Si es la misma, no hacer nada
        if ($localData['commit'] === $latestCommit) {
            if (isset($_GET['debug'])) echo "✨ Ya estás en la última versión.<br>";
            return true; 
        }
        
        // 4. ¡HAY ACTUALIZACIÓN! Descargar ZIP via API
        $zipUrl = "https://api.github.com/repos/$repoUser/$repoName/zipball/$branch";
        $zipFile = __DIR__ . "/temp_update.zip";
        
        if (isset($_GET['debug'])) echo "📥 Descargando actualización...<br>";
        $zipContent = @file_get_contents($zipUrl, false, $context);
        if (!$zipContent) {
            if (isset($_GET['debug'])) echo "❌ Error: No se pudo descargar el archivo ZIP.<br>";
            return false;
        }
        
        file_put_contents($zipFile, $zipContent);
        
        // 5. Extraer y Sobrescribir
        if (!class_exists('ZipArchive')) {
            if (isset($_GET['debug'])) echo "❌ Error: La extensión ZipArchive no está habilitada en este hosting.<br>";
            return false;
        }

        $zip = new ZipArchive;
        if ($zip->open($zipFile) === TRUE) {
            if (isset($_GET['debug'])) echo "📂 Extrayendo archivos...<br>";
            $extractPath = __DIR__ . "/../";
            $tempFolder = __DIR__ . "/temp_extract/";
            @mkdir($tempFolder);
            $zip->extractTo($tempFolder);
            $zip->close();
            
            $subdirs = glob($tempFolder . '*', GLOB_ONLYDIR);
            if (!empty($subdirs)) {
                $innerFolder = $subdirs[0] . '/';
                recurseCopy($innerFolder, $extractPath);
                if (isset($_GET['debug'])) echo "🚀 Archivos actualizados correctamente.<br>";
            }
            
            deleteDir($tempFolder);
            unlink($zipFile);
            
            file_put_contents($versionFile, json_encode([
                'commit' => $latestCommit,
                'date' => date('Y-m-d H:i:s'),
                'author' => $githubData['commit']['author']['name']
            ]));
            
            return true;
        }
    } catch (Exception $e) {
        if (isset($_GET['debug'])) echo "❌ Error crítico: " . $e->getMessage() . "<br>";
        return false;
    }
    return false;
}

// Funciones auxiliares para manejo de archivos
function recurseCopy($src, $dst) {
    $dir = opendir($src);
    @mkdir($dst);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                recurseCopy($src . '/' . $file, $dst . '/' . $file);
            } else {
                $success = copy($src . '/' . $file, $dst . '/' . $file);
                if (isset($_GET['debug'])) {
                    echo ($success ? "  ✅ " : "  ❌ Error: ") . "Copiando $file...<br>";
                }
            }
        }
    }
    closedir($dir);
}

function deleteDir($dirPath) {
    if (!is_dir($dirPath)) return;
    if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') $dirPath .= '/';
    $files = glob($dirPath . '*', GLOB_MARK);
    foreach ($files as $file) {
        if (is_dir($file)) deleteDir($file);
        else unlink($file);
    }
    rmdir($dirPath);
}
