<?php
/**
 * ===================================================================
 * GIT SYNC - Sistema de Auto-Actualización Silenciosa (VERSIÓN 4.6)
 * ===================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// COMANDO MAESTRO: Purga de LiteSpeed Cache y OPcache
if (function_exists('opcache_reset')) @opcache_reset();
header("X-LiteSpeed-Purge: *"); // Limpia todo el caché del servidor

if (php_sapi_name() === 'cli' || isset($_GET['debug'])) {
    if (isset($_GET['check'])) {
        $file = dirname(__DIR__) . '/' . $_GET['check'];
        if (file_exists($file)) {
            header("Cache-Control: no-cache, no-store, must-revalidate");
            echo "📄 <b>Contenido real en disco:</b><br>";
            echo "<pre>" . htmlspecialchars(file_get_contents($file)) . "</pre>";
        }
        exit;
    }
    syncWithGithub();
    echo "<br>🏁 <b>LIMPIEZA DE LITESPEED ENVIADA.</b> Revisa ahora con ?nocache=true";
}

function syncWithGithub() {
    $repoUser = "rikiluciano"; $repoName = "numerosrd"; $branch = "main";
    $token = "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";
    $versionFile = __DIR__ . '/version.json';
    
    $opts = ["http" => ["method" => "GET", "header" => ["User-Agent: PHP-AutoUpdate", "Authorization: token $token"]]];
    $context = stream_context_create($opts);
    
    $currentVersion = '';
    if (file_exists($versionFile)) {
        $versionData = json_decode(file_get_contents($versionFile), true);
        if (isset($versionData['commit'])) {
            $currentVersion = $versionData['commit'];
        }
    }

    $response = @file_get_contents("https://api.github.com/repos/$repoUser/$repoName/commits/$branch", false, $context);
    if (!$response) return false;
    $latestCommit = json_decode($response, true)['sha'];

    // Si ya estamos en la última versión, no hacemos nada (a menos que se fuerce)
    if ($currentVersion === $latestCommit && !isset($_GET['force'])) {
        return true; 
    }

    $zipContent = @file_get_contents("https://api.github.com/repos/$repoUser/$repoName/zipball/$branch", false, $context);
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
            $rootDest = __DIR__; 
            $repoBackend = $innerFolder . "/backend";
            smartCopy(is_dir($repoBackend) ? $repoBackend : $innerFolder, $rootDest);
        }
        deleteDir($tempFolder); unlink($zipFile);
        clearstatcache();
        file_put_contents($versionFile, json_encode(['commit' => $latestCommit]));
        return true;
    }
    return false;
}

function smartCopy($source, $dest) {
    if (!is_dir($dest)) {
        @mkdir($dest, 0755, true);
    }
    foreach (scandir($source) as $item) {
        if ($item == '.' || $item == '..') continue;
        $srcPath = $source . DIRECTORY_SEPARATOR . $item;
        $dstPath = $dest . DIRECTORY_SEPARATOR . $item;
        if (is_dir($srcPath)) {
            smartCopy($srcPath, $dstPath);
        } else {
            @unlink($dstPath);
            if (@copy($srcPath, $dstPath)) {
                @touch($dstPath, time() + 3600); // Forzar fecha futura para romper caché
            }
        }
    }
}

function deleteDir($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), array('.','..'));
    foreach ($files as $file) { (is_dir("$dir/$file")) ? deleteDir("$dir/$file") : unlink("$dir/$file"); }
    return rmdir($dir);
}
