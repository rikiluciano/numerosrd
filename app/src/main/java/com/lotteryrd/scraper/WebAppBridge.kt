package com.lotteryrd.scraper

import android.content.Context
import android.webkit.JavascriptInterface
import android.widget.Toast

/**
 * WebAppBridge — Puente entre la WebApp PHP y la app Android nativa
 * Permite que el JavaScript del frontend llame a funciones nativas de Android
 */
class WebAppBridge(private val context: Context) {

    /**
     * Llamado cuando la página ha cargado completamente
     */
    @JavascriptInterface
    fun onPageLoaded(title: String) {
        // Podemos hacer algo con el título si queremos
    }

    /**
     * Mostrar un toast nativo desde JavaScript
     */
    @JavascriptInterface
    fun showToast(message: String) {
        Toast.makeText(context, message, Toast.LENGTH_SHORT).show()
    }

    /**
     * Mostrar un toast largo desde JavaScript
     */
    @JavascriptInterface
    fun showToastLong(message: String) {
        Toast.makeText(context, message, Toast.LENGTH_LONG).show()
    }

    /**
     * Obtener la versión de la app
     */
    @JavascriptInterface
    fun getAppVersion(): String {
        return try {
            val pInfo = context.packageManager.getPackageInfo(context.packageName, 0)
            pInfo.versionName ?: "1.0.0"
        } catch (e: Exception) {
            "1.0.0"
        }
    }

    /**
     * Obtener el nombre del dispositivo
     */
    @JavascriptInterface
    fun getDeviceInfo(): String {
        return "${android.os.Build.MANUFACTURER} ${android.os.Build.MODEL} (Android ${android.os.Build.VERSION.RELEASE})"
    }

    /**
     * Verificar si hay conexión de red
     */
    @JavascriptInterface
    fun isNetworkAvailable(): Boolean {
        val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as android.net.ConnectivityManager
        val network = cm.activeNetwork ?: return false
        val caps = cm.getNetworkCapabilities(network) ?: return false
        return caps.hasCapability(android.net.NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    /**
     * Vibrar el dispositivo (feedback háptico)
     */
    @JavascriptInterface
    fun vibrate(durationMs: Long = 50) {
        try {
            val vibrator = if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.S) {
                val vm = context.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as android.os.VibratorManager
                vm.defaultVibrator
            } else {
                @Suppress("DEPRECATION")
                context.getSystemService(Context.VIBRATOR_SERVICE) as android.os.Vibrator
            }
            if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.O) {
                vibrator.vibrate(android.os.VibrationEffect.createOneShot(durationMs, android.os.VibrationEffect.DEFAULT_AMPLITUDE))
            } else {
                @Suppress("DEPRECATION")
                vibrator.vibrate(durationMs)
            }
        } catch (e: Exception) {
            // Ignorar si no hay vibrador
        }
    }
}
