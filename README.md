# 🎰 Lottery Scraper RD — Sistema Inteligente de Loterías Dominicanas

**v4.0** | PHP + SQLite + Firebase + DeepSeek IA + Perplexity | App Android

---

## 📋 Descripción

Sistema completo de extracción inteligente de resultados de loterías dominicanas desde [loteriasdominicanas.com](https://loteriasdominicanas.com).

- **Extracción histórica**: desde el 01-01-2012 hasta hoy (una fecha por minuto)
- **Tiempo real**: una vez alcanzado hoy, extrae resultados del día cada minuto
- **Agente IA**: DeepSeek valida, Perplexity verifica, el agente corrige y aprende
- **Firebase**: sincronización en la nube de todos los resultados
- **App Android**: acceso móvil via WebView desde Android Studio

---

## 🚀 Instalación Paso a Paso (x10hosting)

### 1. Subir archivos a x10hosting

1. Inicia sesión en tu panel de control de **x10hosting** (DirectAdmin o cPanel).
2. Ve al **Administrador de Archivos** (File Manager).
3. Navega a la carpeta `public_html`.
4. Sube todos los archivos que están dentro de la carpeta `backend/` de este proyecto a `public_html/resultados/` (o directamente a `public_html` si quieres que sea la raíz de tu sitio).

### 2. Configurar Base de Datos MySQL

x10hosting no soporta SQLite, por lo que usamos MySQL:

1. En tu panel de hosting, ve a **Bases de datos MySQL**.
2. Crea una nueva base de datos (ej. `tuusuario_loteria`).
3. Crea un nuevo usuario y contraseña.
4. Asigna el usuario a la base de datos con **Todos los privilegios**.

### 3. Configurar APIs y Conexión

Edita el archivo `config.php` desde el Administrador de Archivos de x10hosting:

```php
// Configuración MySQL
define('DB_HOST',     'localhost');
define('DB_NAME',     'tuusuario_loteria'); // La DB que creaste
define('DB_USER',     'tuusuario_user');    // El usuario que creaste
define('DB_PASS',     'tu_contraseña');     // La contraseña

// DeepSeek AI (validación de datos)
$DEEPSEEK_CONFIG = [
    'api_key' => 'TU_API_KEY_DEEPSEEK',
];

// Perplexity Sonar-Pro (verificación contextual)
$PERPLEXITY_CONFIG = [
    'api_key' => 'TU_API_KEY_PERPLEXITY',
];

// Token de seguridad del cron
define('CRON_TOKEN', 'MI_TOKEN_SECRETO_SEGURO_2024');
```

Al acceder por primera vez a `https://tu-usuario.x10.mx/resultados/`, el sistema creará las tablas automáticamente.

---

## ⏰ Configurar el Cronjob (en x10hosting)

Para que el sistema extraiga resultados automáticamente:

1. Ve a tu panel de x10hosting y busca la opción **Cron Jobs** (Tareas Programadas).
2. Añade un nuevo Cron Job para que se ejecute **cada minuto** (`* * * * *`).
3. En el comando (Command) ingresa lo siguiente usando `wget` o `curl`:

**Opción con wget:**
```bash
wget -q -O /dev/null "https://tu-usuario.x10.mx/resultados/cronjob.php?token=MI_TOKEN_SECRETO_SEGURO_2024"
```

**Opción con curl:**
```bash
curl -s "https://tu-usuario.x10.mx/resultados/cronjob.php?token=MI_TOKEN_SECRETO_SEGURO_2024" >/dev/null 2>&1
```

Asegúrate de cambiar `tu-usuario.x10.mx` por tu dominio real y usar el mismo `CRON_TOKEN` que pusiste en `config.php`.

---

## 📱 App Android (Android Studio)

### Abrir el proyecto

1. Abre **Android Studio**
2. `File → Open` → selecciona la carpeta `LotteryApp/`
3. Espera que Gradle sincronice

### Configurar la URL del servidor

La app viene preconfigurada con una URL de ejemplo.

1. Abre `app/src/main/java/com/lotteryrd/scraper/MainActivity.kt`
2. En la línea 34, cambia `DEFAULT_URL` a tu dominio de x10hosting:
   ```kotlin
   const val DEFAULT_URL = "https://tu-usuario.x10.mx/resultados/index.php"
   ```

### Compilar APK y Probar

1. Conecta tu teléfono (Samsung SM-A035M).
2. Presiona el botón verde de "Run" (▶) en Android Studio.
3. Si la URL cambia en el futuro, puedes abrir el menú de la app (⋮) → **Configurar URL** e ingresar el nuevo dominio directamente desde el teléfono sin necesidad de recompilar.

---

## 🌐 URLs de la API

| Endpoint | Descripción |
|----------|-------------|
| `api.php?action=status` | Estado general del sistema |
| `api.php?action=results&date=2024-01-15` | Resultados por fecha |
| `api.php?action=progress` | Progreso de extracción histórica |
| `api.php?action=stats` | Estadísticas globales |
| `api.php?action=logs` | Logs de actividad |
| `api.php?action=firebase_test` | Test de conexión Firebase |
| `POST api.php?action=reset` | Reset local (solo en dev) |
| `POST api.php?action=reset_firebase` | Reset en Firebase |
| `cronjob.php?token=TOKEN` | Ejecutar cron manualmente |

---

## 🏢 Loterías Cubiertas

| ID | Empresa | País |
|----|---------|------|
| 10 | Lotería Nacional | RD |
| 9  | Leidsa | RD |
| 11 | Lotería Real | RD |
| 12 | Loteka | RD |
| 13 | Americanas (NY, FL) | US |
| 98 | La Primera | RD |
| 106 | La Suerte Dominicana | RD |
| 114 | LoteDom | RD |
| 120 | Anguila | AIA |
| 124 | King Lottery | RD |

---

## 🤖 Agente IA — Funcionamiento

El agente opera en **5 pasos** por cada registro extraído:

1. **Validación básica** — estructura, campos obligatorios, rango de números
2. **Anti-duplicados** — compara con el lote actual y la BD
3. **DeepSeek** — análisis profundo, detección de anomalías, sugerencias de corrección
4. **Perplexity** — verificación contextual en internet (solo si DeepSeek detecta anomalías)
5. **Auto-corrección** — aplica las correcciones sugeridas con alta confianza

El agente **aprende**: guarda patrones de corrección en `cache/ai_learning_data.json`.

---

## 📁 Estructura del Backend

```
resultados/
├── config.php          ← Configuración maestra (editar aquí)
├── database.php        ← Capa de datos SQLite
├── scraper.php         ← Motor de scraping HTML
├── date_manager.php    ← Gestión de fechas (histórico/tiempo real)
├── ai_agent.php        ← Agente IA autónomo
├── firebase_config.php ← Sincronización Firebase
├── cronjob.php         ← Orquestador principal
├── api.php             ← API REST JSON
├── index.php           ← Dashboard web
├── .htaccess           ← Seguridad Apache
├── firebase-service-account.json  ← (TÚ LO CREAS) credenciales Firebase
├── data/
│   └── lottery.db      ← Base de datos SQLite (se crea automáticamente)
├── logs/               ← Logs del sistema
├── cache/              ← Caché del agente IA
└── temp/               ← Archivos temporales (lock files)
```

---

## ⚠️ Notas Importantes

- **Zona horaria**: America/Santo_Domingo (UTC-4) — forzada en todo el sistema
- **Rate limiting**: el scraper pausa 500ms entre requests para no sobrecargar el sitio
- **Días festivos**: el sistema salta días sin resultados automáticamente
- **Producción**: cambia `APP_ENV` a `production` en config.php para deshabilitar el reset
- **Token**: **CAMBIA** `CRON_TOKEN` antes de subir a producción

---

*Lottery Scraper RD v4.0 — Zona Horaria: America/Santo_Domingo*
