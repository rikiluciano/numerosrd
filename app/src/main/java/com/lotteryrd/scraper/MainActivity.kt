package com.lotteryrd.scraper

import android.annotation.SuppressLint
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Bundle
import android.view.Menu
import android.view.MenuItem
import android.view.View
import android.webkit.*
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.webkit.WebSettingsCompat
import androidx.webkit.WebViewFeature
import com.lotteryrd.scraper.databinding.ActivityMainBinding

/**
 * MainActivity — Actividad principal con WebView
 *
 * Carga el dashboard del sistema Lottery Scraper RD.
 * El servidor PHP corre en XAMPP localmente (o en red local).
 * La URL del servidor se configura desde Settings.
 */
class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private var serverUrl: String = ""

    companion object {
        const val PREFS_NAME = "LotteryRDPrefs"
        const val KEY_SERVER_URL = "server_url"
        const val DEFAULT_URL = "https://rlabs.x10.mx/resultados/index.php"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        supportActionBar?.hide() // Ocultar el titulo duplicado nativo
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        setSupportActionBar(binding.toolbar)
        supportActionBar?.title = "🎰 LotteryApp"
        supportActionBar?.elevation = 0f

        // Cargar URL configurada o usar la predeterminada
        val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        serverUrl = prefs.getString(KEY_SERVER_URL, DEFAULT_URL) ?: DEFAULT_URL

        // OCULTAR BARRA DE TÍTULO NATIVA (Solución al título duplicado)
        supportActionBar?.hide()

        setupWebView()
        setupSwipeRefresh()

        if (isNetworkAvailable()) {
            loadDashboard()
        } else {
            showNoInternetDialog()
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        val webView = binding.webView
        val settings = webView.settings

        // Configuración básica
        settings.javaScriptEnabled = true
        settings.domStorageEnabled = true
        settings.databaseEnabled = true
        settings.loadWithOverviewMode = true
        settings.useWideViewPort = true
        settings.setSupportZoom(true)
        settings.builtInZoomControls = true
        settings.displayZoomControls = false
        settings.cacheMode = WebSettings.LOAD_DEFAULT
        settings.mixedContentMode = WebSettings.MIXED_CONTENT_ALWAYS_ALLOW

        // Habilitar modo oscuro si el sistema lo usa
        if (WebViewFeature.isFeatureSupported(WebViewFeature.FORCE_DARK)) {
            @Suppress("DEPRECATION")
            WebSettingsCompat.setForceDark(settings, WebSettingsCompat.FORCE_DARK_AUTO)
        }

        // User agent personalizado
        settings.userAgentString = "LotteryRD-Android/1.0 " + settings.userAgentString

        // JavaScript Interface para comunicación nativa
        webView.addJavascriptInterface(WebAppBridge(this), "AndroidBridge")

        // WebViewClient para manejar navegación
        webView.webViewClient = object : WebViewClient() {
            override fun onPageStarted(view: WebView?, url: String?, favicon: Bitmap?) {
                binding.progressBar.visibility = View.VISIBLE
                binding.swipeRefresh.isRefreshing = false
            }

            override fun onPageFinished(view: WebView?, url: String?) {
                binding.progressBar.visibility = View.GONE
                binding.swipeRefresh.isRefreshing = false

                // Inyectar mejoras nativas
                injectNativeEnhancements()
            }

            override fun onReceivedError(
                view: WebView?,
                request: WebResourceRequest?,
                error: WebResourceError?
            ) {
                binding.progressBar.visibility = View.GONE
                if (request?.isForMainFrame == true) {
                    showConnectionError()
                }
            }

            @Deprecated("Deprecated in Java")
            override fun onReceivedError(
                view: WebView?,
                errorCode: Int,
                description: String?,
                failingUrl: String?
            ) {
                binding.progressBar.visibility = View.GONE
                showConnectionError()
            }

            override fun shouldOverrideUrlLoading(
                view: WebView?,
                request: WebResourceRequest?
            ): Boolean {
                val url = request?.url?.toString() ?: return false
                // Permitir navegación dentro del dominio del servidor
                return if (url.startsWith(getBaseServerUrl())) {
                    view?.loadUrl(url)
                    true
                } else {
                    false
                }
            }
        }

        // WebChromeClient para consola JS y permisos
        webView.webChromeClient = object : WebChromeClient() {
            override fun onProgressChanged(view: WebView?, newProgress: Int) {
                if (newProgress < 100) {
                    binding.progressBar.visibility = View.VISIBLE
                    binding.progressBar.progress = newProgress
                } else {
                    binding.progressBar.visibility = View.GONE
                }
            }

            override fun onConsoleMessage(consoleMessage: ConsoleMessage?): Boolean {
                // Capturar logs de JS para debugging
                return super.onConsoleMessage(consoleMessage)
            }
        }
    }

    private fun setupSwipeRefresh() {
        binding.swipeRefresh.setColorSchemeColors(
            getColor(R.color.color_primary),
            getColor(R.color.color_secondary),
            getColor(R.color.color_accent)
        )
        binding.swipeRefresh.setOnRefreshListener {
            binding.webView.reload()
        }
    }

    private fun loadDashboard() {
        binding.errorLayout.visibility = View.GONE
        binding.webView.visibility = View.VISIBLE
        binding.webView.loadUrl(serverUrl)
    }

    private fun showConnectionError() {
        binding.progressBar.visibility = View.GONE
        binding.webView.visibility = View.GONE
        binding.errorLayout.visibility = View.VISIBLE
        binding.swipeRefresh.isRefreshing = false
        binding.btnRetry.setOnClickListener {
            binding.errorLayout.visibility = View.GONE
            binding.webView.visibility = View.VISIBLE
            loadDashboard()
        }
        binding.btnSettings.setOnClickListener {
            openSettings()
        }
    }

    private fun showNoInternetDialog() {
        AlertDialog.Builder(this)
            .setTitle("Sin conexión")
            .setMessage("No hay conexión de red disponible. Verifica que XAMPP esté corriendo y que estés en la misma red WiFi que tu computadora.")
            .setPositiveButton("Reintentar") { _, _ -> 
                if (isNetworkAvailable()) loadDashboard() 
                else showNoInternetDialog()
            }
            .setNegativeButton("Configurar") { _, _ -> openSettings() }
            .setCancelable(false)
            .show()
    }

    private fun injectNativeEnhancements() {
        // Deshabilitar el zoom con pellizco en elementos de formulario
        val js = """
            (function() {
                // Notificar a la app que la página cargó
                if (window.AndroidBridge) {
                    AndroidBridge.onPageLoaded(document.title);
                }
                // Ajustar viewport para móvil
                var meta = document.querySelector('meta[name="viewport"]');
                if (!meta) {
                    meta = document.createElement('meta');
                    meta.name = 'viewport';
                    document.getElementsByTagName('head')[0].appendChild(meta);
                }
                meta.content = 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no';
            })();
        """.trimIndent()
        binding.webView.evaluateJavascript(js, null)
    }

    private fun getBaseServerUrl(): String {
        return try {
            val uri = android.net.Uri.parse(serverUrl)
            "${uri.scheme}://${uri.host}${if (uri.port != -1) ":${uri.port}" else ""}"
        } catch (e: Exception) {
            serverUrl
        }
    }

    private fun isNetworkAvailable(): Boolean {
        val cm = getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        val network = cm.activeNetwork ?: return false
        val caps = cm.getNetworkCapabilities(network) ?: return false
        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    private fun openSettings() {
        startActivityForResult(Intent(this, SettingsActivity::class.java), 100)
    }

    @Deprecated("Deprecated in Java")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode == 100 && resultCode == RESULT_OK) {
            // URL actualizada, recargar
            val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            serverUrl = prefs.getString(KEY_SERVER_URL, DEFAULT_URL) ?: DEFAULT_URL
            loadDashboard()
        }
    }

    // --- MENÚ DE OPCIONES SIMPLIFICADO ---
    override fun onCreateOptionsMenu(menu: Menu): Boolean {
        menu.add(0, 1, 0, "Inicio")
        return true
    }

    override fun onOptionsItemSelected(item: MenuItem): Boolean {
        return when (item.itemId) {
            1 -> {
                loadDashboard() // Vuelve a cargar el inicio
                true
            }
            else -> super.onOptionsItemSelected(item)
        }
    }

    override fun onBackPressed() {
        if (binding.webView.canGoBack()) {
            binding.webView.goBack()
        } else {
            super.onBackPressed()
        }
    }

    override fun onResume() {
        super.onResume()
        binding.webView.onResume()
    }

    override fun onPause() {
        super.onPause()
        binding.webView.onPause()
    }

    override fun onDestroy() {
        binding.webView.destroy()
        super.onDestroy()
    }
}
