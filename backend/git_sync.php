<?php
/**
 * ===================================================================
 * GIT SYNC - Sistema de Auto-Actualización Silenciosa
 * ===================================================================
 */

function syncWithGithub()
{
    // --- CONFIGURACIÓN ---
    $repoUser = "rikiluciano";
    $repoName = "numerosrd";
    $branch = "main";
    $versionFile = __DIR__ . '/version.json';

    // 1. Obtener última versión (commit) de GitHub via API
    $token = "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"; // Tu llave maestra
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
        if (!$response)
            return false;

        $githubData = json_decode($response, true);
        $latestCommit = $githubData['sha'];

        // 2. Leer versión local
        $localData = file_exists($versionFile) ? json_decode(file_get_contents($versionFile), true) : ['commit' => ''];

        // 3. Si es la misma, no hacer nada
        if ($localData['commit'] === $latestCommit) {
            return true;
        }

        // 4. ¡HAY ACTUALIZACIÓN! Descargar ZIP via API
        $zipUrl = "https://api.github.com/repos/$repoUser/$repoName/zipball/$branch";
        $zipFile = __DIR__ . "/temp_update.zip";

        $zipContent = @file_get_contents($zipUrl, false, $context);
        if (!$zipContent)
            return false;

        file_put_contents($zipFile, $zipContent);

        if (!file_exists($zipFile))
            return false;

        // 5. Extraer y Sobrescribir
        $zip = new ZipArchive;
        if ($zip->open($zipFile) === TRUE) {
            $extractPath = __DIR__ . "/../";
            // El zip de GitHub viene dentro de una carpeta llamada "user-repo-hash"
            $tempFolder = __DIR__ . "/temp_extract/";
            $zip->extractTo($tempFolder);
            $zip->close();

            // Detectar la carpeta interior dinámicamente (GitHub le añade un hash al final)
            $subdirs = glob($tempFolder . '*', GLOB_ONLYDIR);
            if (!empty($subdirs)) {
                $innerFolder = $subdirs[0] . '/';
                recurseCopy($innerFolder, $extractPath);
            }

            // Limpiar
            deleteDir($tempFolder);
            unlink($zipFile);

            // 6. Actualizar archivo de versión local
            file_put_contents($versionFile, json_encode([
                'commit' => $latestCommit,
                'date' => date('Y-m-d H:i:s'),
                'author' => $githubData['commit']['author']['name']
            ]));

            return true;
        }
    } catch (Exception $e) {
        return false;
    }
    return false;
}

// Funciones auxiliares para manejo de archivos
function recurseCopy($src, $dst)
{
    $dir = opendir($src);
    @mkdir($dst);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                recurseCopy($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

function deleteDir($dirPath)
{
    if (!is_dir($dirPath))
        return;
    if (substr($dirPath, strlen($dirPath) - 1, 1) != '/')
        $dirPath .= '/';
    $files = glob($dirPath . '*', GLOB_MARK);
    foreach ($files as $file) {
        if (is_dir($file))
            deleteDir($file);
        else
            unlink($file);
    }
    rmdir($dirPath);
}
