<?php
/**
 * ===================================================================
 * GIT SYNC - Sistema de Auto-Actualización Silenciosa (VERSIÓN 4.0)
 * ===================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- EJECUCIÓN DIRECTA ---
if (php_sapi_name() === 'cli' || isset($_GET['debug'])) {
    if (isset($_GET['check'])) {
        $file = dirname(__DIR__) . '/' . $_GET['check'];
        if (file_exists($file)) {
            echo "📄 <b>Verificando archivo:</b> " . $_GET['check'] . "<br>";
            echo "<pre>" . htmlspecialchars(substr(file_get_contents($file), 0, 1000)) . "</pre>";
        } else {
            echo "❌ El archivo no existe en: $file";
        }
        exit;
    }
    
    $result = syncWithGithub();
    if (isset($_GET['debug'])) {
        echo $result ? "<br>🏁 <b>ACTUALIZACIÓN EXITOSA</b>. Revisa tu web ahora." : "<br>🏁 <b>AVISO:</b> Sin cambios o error.";
    }
}

function syncWithGithub() {
    $repoUser = "rikiluciano"; 
    $repoName = "numerosrd";    
    $branch   = "main";
    $token    = "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";
    $versionFile = __DIR__ . '/version.json';
    
    if (isset($_GET['debug'])) echo "🚀 <b>Iniciando Sincronización Inteligente...</b><br>";

    $opts = ["http" => ["method" => "GET", "header" => ["User-Agent: PHP-AutoUpdate", "Authorization: token $token"]]];
    $context = stream_context_create($opts);
    
    $apiUrl = "https://api.github.com/repos/$repoUser/$repoName/commits/$branch";
    $response = @file_get_contents($apiUrl, false, $context);
    if (!$response) return false;
    $githubData = json_decode($response, true);
    $latestCommit = $githubData['sha'];

    $localData = file_exists($versionFile) ? json_decode(file_get_contents($versionFile), true) : ['commit' => ''];
    $force = isset($_GET['force']);
    
    if (isset($_GET['debug'])) echo "📦 GitHub: <code>" . substr($latestCommit,0,7) . "</code> | 🏠 Local: <code>" . substr($localData['commit'],0,7) . "</code><br>";

    if ($localData['commit'] === $latestCommit && !$force) return true;

    $zipUrl = "https://api.github.com/repos/$repoUser/$repoName/zipball/$branch";
    $zipContent = @file_get_contents($zipUrl, false, $context);
    if (!$zipContent) return false;
    
    $zipFile = __DIR__ . "/temp_update.zip";
    file_put_contents($zipFile, $zipContent);

    $zip = new ZipArchive;
    if ($zip->open($zipFile) === TRUE) {
        $tempFolder = __DIR__ . "/temp_extract/";
        if (is_dir($tempFolder)) deleteDir($tempFolder);
        mkdir($tempFolder, 0755, true);
        $zip->extractTo($tempFolder);
        $zip->close();
        
        $subdirs = glob($tempFolder . '*', GLOB_ONLYDIR);
        if (!empty($subdirs)) {
            $innerFolder = $subdirs[0];
            $rootDest = realpath(__DIR__ . "/../"); 
            
            // INTELIGENCIA DE CARPETAS:
            // Si el repo tiene una carpeta 'backend', y nosotros estamos en un hosting que actúa como raíz
            $repoBackend = $innerFolder . "/backend";
            if (is_dir($repoBackend)) {
                if (isset($_GET['debug'])) echo "✨ <b>Detectada carpeta 'backend' en el repo. Sincronizando contenido...</b><br>";
                smartCopy($repoBackend, $rootDest);
            } else {
                smartCopy($innerFolder, $rootDest);
            }
        }
        
        deleteDir($tempFolder);
        unlink($zipFile);
        
        file_put_contents($versionFile, json_encode(['commit' => $latestCommit, 'date' => date('Y-m-d H:i:s')]));
        return true;
    }
    return false;
}

function smartCopy($source, $dest) {
    if (!is_dir($source)) return;
    if (!is_dir($dest)) mkdir($dest, 0755, true);

    foreach (scandir($source) as $item) {
        if ($item == '.' || $item == '..') continue;
        $srcPath = $source . DIRECTORY_SEPARATOR . $item;
        $dstPath = $dest . DIRECTORY_SEPARATOR . $item;

        if (is_dir($srcPath)) {
            if (isset($_GET['debug'])) echo "📁 Carpeta: <b>$item</b><br>";
            smartCopy($srcPath, $dstPath);
        } else {
            if ($item == 'version.json') continue;
            $success = @copy($srcPath, $dstPath);
            if (isset($_GET['debug'])) echo ($success ? "  ✅" : "  ❌") . " $item<br>";
        }
    }
}

function deleteDir($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), array('.','..'));
    foreach ($files as $file) { (is_dir("$dir/$file")) ? deleteDir("$dir/$file") : unlink("$dir/$file"); }
    return rmdir($dir);
}
