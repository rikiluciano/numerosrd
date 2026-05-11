package com.rlabs.lotteryapp

import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import com.rlabs.lotteryapp.databinding.ActivitySettingsBinding

/**
 * SettingsActivity — Configuración de la URL del servidor PHP
 *
 * Permite al usuario configurar la IP/URL del servidor XAMPP
 * donde corre el backend del Lottery Scraper RD.
 *
 * Ejemplos de URLs válidas:
 *   - http://192.168.1.100/resultados/index.php  (red WiFi local)
 *   - http://10.0.2.2/resultados/index.php       (emulador Android)
 *   - https://mi-servidor.com/resultados/        (servidor remoto)
 */
class SettingsActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySettingsBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySettingsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        setSupportActionBar(binding.toolbar)
        supportActionBar?.apply {
            title = "⚙️ Configuración"
            setDisplayHomeAsUpEnabled(true)
        }

        loadCurrentSettings()
        setupClickListeners()
    }

    private fun loadCurrentSettings() {
        val prefs = getSharedPreferences(MainActivity.PREFS_NAME, Context.MODE_PRIVATE)
        val currentUrl = prefs.getString(MainActivity.KEY_SERVER_URL, MainActivity.DEFAULT_URL)
        binding.etServerUrl.setText(currentUrl)
    }

    private fun setupClickListeners() {
        // Botón Guardar
        binding.btnSave.setOnClickListener {
            val url = binding.etServerUrl.text.toString().trim()
            if (url.isEmpty()) {
                binding.etServerUrl.error = "La URL no puede estar vacía"
                return@setOnClickListener
            }
            if (!url.startsWith("http://") && !url.startsWith("https://")) {
                binding.etServerUrl.error = "La URL debe comenzar con http:// o https://"
                return@setOnClickListener
            }
            saveSettings(url)
        }

        // Botón Restaurar
        binding.btnRestore.setOnClickListener {
            binding.etServerUrl.setText(MainActivity.DEFAULT_URL)
        }

        // Chip de ayuda para tu Hosting
        binding.chipEmulator.text = "☁️ x10hosting"
        binding.chipEmulator.setOnClickListener {
            binding.etServerUrl.setText("https://tu-usuario.x10.mx/resultados/index.php")
        }

        // Chip de ayuda para WiFi (si aún quisiera probar local)
        binding.chipWifi.setOnClickListener {
            binding.etServerUrl.setText("http://192.168.1.XXX/resultados/index.php")
            Toast.makeText(this, "Reemplaza XXX con la IP de tu PC", Toast.LENGTH_LONG).show()
        }

        // Chip admin
        binding.chipAdmin.setOnClickListener {
            val prefs = getSharedPreferences(MainActivity.PREFS_NAME, Context.MODE_PRIVATE)
            val base = prefs.getString(MainActivity.KEY_SERVER_URL, MainActivity.DEFAULT_URL) ?: MainActivity.DEFAULT_URL
            val adminUrl = base.replace("index.php", "admin.html")
            binding.etServerUrl.setText(adminUrl)
        }
    }

    private fun saveSettings(url: String) {
        val prefs = getSharedPreferences(MainActivity.PREFS_NAME, Context.MODE_PRIVATE)
        prefs.edit()
            .putString(MainActivity.KEY_SERVER_URL, url)
            .apply()

        Toast.makeText(this, "✅ Configuración guardada", Toast.LENGTH_SHORT).show()
        setResult(RESULT_OK)
        finish()
    }

    override fun onSupportNavigateUp(): Boolean {
        onBackPressed()
        return true
    }
}
