<?php
/**
 * ===================================================================
 * GIT SYNC - Sistema de Auto-Actualización Silenciosa (VERSIÓN 2.0)
 * ===================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- EJECUCIÓN DIRECTA ---
if (php_sapi_name() === 'cli' || isset($_GET['debug'])) {
    $result = syncWithGithub();
    if (isset($_GET['debug'])) {
        echo $result ? "<br>🏁 <b>¡ÉXITO!</b> Sistema actualizado." : "<br>🏁 <b>AVISO:</b> No hubo cambios o ocurrió un error.";
    }
}

function syncWithGithub() {
    $repoUser = "rikiluciano"; 
    $repoName = "numerosrd";    
    $branch   = "main";
    $token    = "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";
    $versionFile = __DIR__ . '/version.json';
    
    if (isset($_GET['debug'])) echo "🚀 <b>Iniciando Sincronización...</b><br>";

    $opts = [
        "http" => [
            "method" => "GET",
            "header" => ["User-Agent: PHP-AutoUpdate", "Authorization: token $token"]
        ]
    ];
    $context = stream_context_create($opts);
    
    // 1. Obtener Commit
    $apiUrl = "https://api.github.com/repos/$repoUser/$repoName/commits/$branch";
    $response = @file_get_contents($apiUrl, false, $context);
    if (!$response) return false;
    $githubData = json_decode($response, true);
    $latestCommit = $githubData['sha'];

    // 2. Comprobar versión
    $localData = file_exists($versionFile) ? json_decode(file_get_contents($versionFile), true) : ['commit' => ''];
    $force = isset($_GET['force']);
    
    if (isset($_GET['debug'])) echo "📦 Commit GitHub: <code>$latestCommit</code><br>🏠 Commit Local: <code>" . ($localData['commit'] ?: 'Ninguno') . "</code>" . ($force ? " [MODO FORZADO]" : "") . "<br>";

    if ($localData['commit'] === $latestCommit && !$force) return true;

    // 3. Descargar y Extraer
    if (isset($_GET['debug'])) echo "📥 Descargando ZIP...<br>";
    $zipUrl = "https://api.github.com/repos/$repoUser/$repoName/zipball/$branch";
    $zipContent = @file_get_contents($zipUrl, false, $context);
    if (!$zipContent) return false;
    
    $zipFile = __DIR__ . "/temp_update.zip";
    file_put_contents($zipFile, $zipContent);

    if (!class_exists('ZipArchive')) {
        if (isset($_GET['debug'])) echo "❌ Error: ZipArchive no habilitado.<br>";
        return false;
    }

    $zip = new ZipArchive;
    if ($zip->open($zipFile) === TRUE) {
        $tempFolder = __DIR__ . "/temp_extract/";
        if (!is_dir($tempFolder)) mkdir($tempFolder, 0755, true);
        $zip->extractTo($tempFolder);
        $zip->close();
        
        $subdirs = glob($tempFolder . '*', GLOB_ONLYDIR);
        if (!empty($subdirs)) {
            $innerFolder = $subdirs[0];
            if (isset($_GET['debug'])) echo "📂 Extrayendo archivos de: <code>$innerFolder</code><br>";
            
            // EL SECRETO: El destino es un nivel arriba de 'backend'
            $rootDest = dirname(__DIR__); 
            recurseCopy($innerFolder, $rootDest);
        }
        
        // Limpieza
        deleteDir($tempFolder);
        unlink($zipFile);
        
        file_put_contents($versionFile, json_encode(['commit' => $latestCommit, 'date' => date('Y-m-d H:i:s')]));
        return true;
    }
    return false;
}

function recurseCopy($src, $dst) {
    $dir = opendir($src);
    if (!is_dir($dst)) @mkdir($dst, 0755, true);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                if (isset($_GET['debug'])) echo "📁 Carpeta: <b>$file</b><br>";
                recurseCopy($src . '/' . $file, $dst . '/' . $file);
            } else {
                $success = @copy($src . '/' . $file, $dst . '/' . $file);
                if (isset($_GET['debug'])) echo ($success ? "  ✅" : "  ❌") . " $file<br>";
            }
        }
    }
    closedir($dir);
}

function deleteDir($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), array('.','..'));
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? deleteDir("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}
