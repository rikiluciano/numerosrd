<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/date_manager.php';

$db = new LotteryDB();
$dm = new DateManager($db);
$state = $db->getState();
$progress = $dm->getProgress();
$next = $dm->getNextDateToProcess();
$today = getRDDate();
$todayResults = $db->getResultsByDateGrouped($today);
$lastDate = $state['last_processed_date'] ?? null;
$lastResults = $lastDate && $lastDate !== $today ? $db->getResultsByDateGrouped($lastDate) : [];
$displayResults = !empty($todayResults) ? $todayResults : $lastResults;
$displayDate = !empty($todayResults) ? $today : ($lastDate ?? $today);
?><!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
  <title>Lottery Scraper RD – Dashboard</title>
  <meta name="description" content="Sistema inteligente de extracción de resultados de loterías dominicanas con IA">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
    rel="stylesheet">
  <style>
    :root {
      --p: #667eea;
      --s: #764ba2;
      --a: #f6ca44;
      --bg: #0f0f1a;
      --card: #1a1a2e;
      --card2: #16213e;
      --text: #e2e8f0;
      --muted: #94a3b8;
      --border: #2d2d4a;
      --r: 14px;
      --shadow: 0 8px 32px rgba(0, 0, 0, .4)
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box
    }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: 'Inter', sans-serif;
      line-height: 1.6;
      overflow-x: hidden
    }

    .app-container {
      max-width: 600px;
      margin: 0 auto;
      padding: 0;
      min-height: 100vh
    }

    .topbar {
      background: linear-gradient(135deg, #13131f, #1a1a2e);
      border-bottom: 1px solid var(--border);
      padding: 14px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 100;
      gap: 12px
    }

    .topbar-brand {
      display: flex;
      align-items: center;
      gap: 10px;
      font-weight: 700;
      font-size: 1.1rem;
      color: #fff
    }

    .topbar-brand span {
      font-size: 1.5rem
    }

    .badge-mode {
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 600;
      letter-spacing: .5px
    }

    .badge-realtime {
      background: rgba(16, 185, 129, .2);
      color: #34d399;
      border: 1px solid rgba(16, 185, 129, .3)
    }

    .badge-historical {
      background: rgba(245, 158, 11, .2);
      color: #fbbf24;
      border: 1px solid rgba(245, 158, 11, .3)
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 12px;
      padding: 16px
    }

    .stat-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--r);
      padding: 16px;
      text-align: center;
      transition: transform .2s;
      cursor: pointer
    }

    .stat-card:active {
      transform: scale(0.97)
    }

    .stat-val {
      font-size: 1.4rem;
      font-weight: 800;
      background: linear-gradient(135deg, #fff, var(--muted));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent
    }

    .stat-lbl {
      font-size: 11px;
      color: var(--muted);
      margin-top: 4px;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 1px
    }

    .section {
      padding: 0 16px 16px
    }

    .section-title {
      font-size: .85rem;
      font-weight: 700;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 1px;
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 8px
    }

    .date-clickable {
      cursor: pointer;
      background: rgba(255, 255, 255, 0.05);
      padding: 4px 10px;
      border-radius: 8px;
      transition: all .2s;
      border: 1px solid transparent
    }

    .date-clickable:hover {
      background: rgba(255, 255, 255, 0.1);
      border-color: var(--p)
    }

    .companies-wrap {
      display: flex;
      flex-direction: column;
      gap: 16px;
      transition: opacity .3s ease-out
    }

    .company-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--r);
      overflow: hidden;
      box-shadow: var(--shadow)
    }

    .company-header {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 14px 16px;
      background: var(--card2);
      border-bottom: 1px solid var(--border);
      cursor: pointer;
      transition: background .2s
    }

    .company-header:hover {
      background: rgba(255, 255, 255, 0.03)
    }

    .company-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      flex-shrink: 0
    }

    .company-name {
      font-weight: 700;
      font-size: .95rem;
      flex: 1
    }

    .draws-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 1px;
      background: var(--border)
    }

    .draw-block {
      background: var(--card2);
      padding: 14px 16px;
      cursor: pointer;
      transition: background .2s
    }

    .draw-block:hover {
      background: rgba(255, 255, 255, 0.05)
    }

    .draw-name {
      font-size: .85rem;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 6px
    }

    .draw-time {
      font-size: .7rem;
      color: var(--muted);
      opacity: .8;
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      gap: 4px
    }

    .numbers-row {
      display: flex;
      gap: 8px;
      flex-wrap: wrap
    }

    .num-ball {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: .9rem;
      flex-shrink: 0;
      box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2)
    }

    .num-special1 {
      background: linear-gradient(135deg, #f6ca44, #f59e0b);
      color: #000
    }

    .num-special2 {
      background: linear-gradient(135deg, #667eea, #764ba2);
      color: #fff
    }

    .num-special3 {
      background: linear-gradient(135deg, #10b981, #059669);
      color: #fff
    }

    .num-normal {
      background: var(--bg);
      color: var(--text);
      border: 1px solid var(--border)
    }

    .empty {
      text-align: center;
      padding: 60px 20px;
      color: var(--muted)
    }

    .empty-icon {
      font-size: 3rem;
      margin-bottom: 16px;
      opacity: 0.5
    }

    #toast {
      position: fixed;
      bottom: 24px;
      left: 50%;
      transform: translateX(-50%);
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 30px;
      padding: 12px 24px;
      font-size: 14px;
      font-weight: 600;
      z-index: 9999;
      opacity: 0;
      transition: opacity .3s;
      pointer-events: none;
      box-shadow: var(--shadow)
    }

    #toast.show {
      opacity: 1
    }

    .ai-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(102, 126, 234, .1);
      border: 1px solid rgba(102, 126, 234, 0.2);
      border-radius: 20px;
      padding: 4px 12px;
      font-size: 11px;
      color: var(--p);
      font-weight: 700
    }

    .ai-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: var(--p);
      box-shadow: 0 0 8px var(--p);
      animation: pulse 2s infinite
    }

    @keyframes pulse {

      0%,
      100% {
        opacity: 1
      }

      50% {
        opacity: .3
      }
    }

    .fade-out {
      opacity: 0
    }

    #calendarInput {
      position: absolute;
      visibility: hidden;
      top: 0;
      left: 0
    }

    /* Modal Styles */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.85);
      backdrop-filter: blur(6px);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 10000;
      padding: 16px
    }

    .modal-content {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--r);
      width: 100%;
      max-width: 480px;
      max-height: 85vh;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      box-shadow: var(--shadow)
    }

    .modal-header {
      padding: 18px 20px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: var(--card2)
    }

    .modal-body {
      overflow-y: auto;
      padding: 10px 0
    }

    .modal-close {
      cursor: pointer;
      font-size: 1.5rem;
      opacity: 0.5;
      line-height: 1
    }

    .modal-close:hover {
      opacity: 1
    }

    .history-item {
      padding: 16px 20px;
      border-bottom: 1px solid var(--border);
      animation: slideIn .3s ease-out forwards
    }

    .history-item:last-child {
      border-bottom: none
    }

    @keyframes slideIn {
      from {
        opacity: 0;
        transform: translateY(10px)
      }

      to {
        opacity: 1;
        transform: translateY(0)
      }
    }

    @media(max-width:480px) {
      .draws-grid {
        grid-template-columns: 1fr
      }
    }
  </style>
</head>

<body>

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-brand"><span>🎰</span> Lottery RD pruba</div>
  </div>

  <?php
  $lastDateFormatted = 'N/A';
  if (!empty($state['last_processed_date'])) {
    $lastDateFormatted = date('d/m/Y', strtotime($state['last_processed_date']));
  }
  ?>
  <!-- Stats -->
  <div class="stats-grid" style="grid-template-columns: repeat(2, 1fr);">
    <div class="stat-card">
      <div class="stat-val" id="stat-count"><?= number_format($db->countResults()) ?></div>
      <div class="stat-lbl">Sorteos en BD</div>
    </div>
    <div class="stat-card">
      <div class="stat-val" id="stat-date"><?= htmlspecialchars($lastDateFormatted) ?></div>
      <div class="stat-lbl">Última Fecha</div>
    </div>
  </div>

  <!-- Results -->
  <div class="section">
    <div class="section-title">🎯 Resultados — <span id="resultsDateLabel" class="date-clickable"
        onclick="openCalendar()"><?= date('d/m/Y', strtotime($displayDate)) ?></span></div>
    <input type="date" id="calendarInput" onchange="onCalendarSelect(this.value)" max="<?= $today ?>">

    <div id="resultsContainer" class="companies-wrap">
      <?php if (empty($displayResults)): ?>
        <div class="empty">
          <div class="empty-icon">📭</div>
          <p>Sin resultados para esta fecha.</p>
        </div>
      <?php else: ?>
        <?php foreach ($displayResults as $slug => $comp): ?>
          <div class="company-card">
            <div class="company-header"
              onclick="showHistory('company', '<?= $slug ?>', '<?= htmlspecialchars($comp['company']['name']) ?>')">
              <div class="company-dot" style="background:<?= htmlspecialchars($comp['company']['color']) ?>"></div>
              <div class="company-name"><?= htmlspecialchars($comp['company']['name']) ?></div>
              <small style="color:var(--muted)"><?= count($comp['draws']) ?> sorteos ❯</small>
            </div>
            <div class="draws-grid">
              <?php foreach ($comp['draws'] as $draw): ?>
                <div class="draw-block"
                  onclick="event.stopPropagation(); showHistory('draw', '<?= $slug ?>', '<?= htmlspecialchars($draw['drawName']) ?>')">
                  <div class="draw-name"><?= htmlspecialchars($draw['drawName']) ?></div>
                  <?php if ($draw['drawTime']): ?>
                    <div class="draw-time">⏰ <?= htmlspecialchars($draw['drawTime']) ?></div><?php endif; ?>
                  <div class="numbers-row">
                    <?php foreach ($draw['numbers'] as $ni => $num):
                      $type = $draw['numberTypes'][$ni] ?? 'normal';
                      $cls = "num-{$type}";
                      ?>
                      <div class="num-ball <?= $cls ?>"><?= str_pad($num, 2, '0', STR_PAD_LEFT) ?></div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Modal Historial -->
  <div id="modalOverlay" class="modal-overlay" onclick="closeModal()">
    <div class="modal-content" onclick="event.stopPropagation()">
      <div class="modal-header">
        <div id="modalTitle" style="font-weight:700">Historial</div>
        <div class="modal-close" onclick="closeModal()">✕</div>
      </div>
      <div id="modalBody" class="modal-body">
        <!-- AJAX -->
      </div>
    </div>
  </div>

  <!-- Logs removed per user request to save space -->



  <div id="toast"></div>

  <script>
    const CRON_TOKEN = '<?= CRON_TOKEN ?>';

    function toast(msg, ok = true) {
      const t = document.getElementById('toast');
      t.textContent = msg;
      t.style.borderColor = ok ? '#10b981' : '#ef4444';
      t.classList.add('show');
      setTimeout(() => t.classList.remove('show'), 3000);
      if (window.AndroidBridge) window.AndroidBridge.showToast(msg);
    }

    async function runCron() {
      toast('⏳ Ejecutando scraper...');
      try {
        const fd = new FormData();
        fd.append('token', CRON_TOKEN);
        const r = await fetch('api.php?action=run_cron', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
          toast('✅ ' + (d.stats?.saved ?? 0) + ' guardados | ' + (d.stats?.extracted ?? 0) + ' extraídos');
          setTimeout(() => location.reload(), 1500);
        } else {
          toast('❌ ' + (d.error || d.message || 'Error'), false);
        }
      } catch (e) { toast('❌ Error de conexión', false); }
    }

    async function loadResults(date) {
      const container = document.getElementById('resultsContainer');

      // Efecto salida suave
      container.classList.add('fade-out');

      try {
        const r = await fetch(`api.php?action=results&date=${date}`);
        const d = await r.json();

        // Pequeño retraso para que el ojo no note el parpadeo
        await new Promise(res => setTimeout(res, 150));

        if (d.success) {
          document.getElementById('resultsDateLabel').textContent = formatDate(date);
          renderResults(d.data || {}, container);
        }
      } catch (e) {
        toast('❌ Error de conexión', false);
      } finally {
        container.classList.remove('fade-out');
      }
    }

    function renderResults(data, container) {
      if (!Object.keys(data).length) {
        container.innerHTML = '<div class="empty"><div class="empty-icon">📭</div><p>Sin resultados para esta fecha</p></div>';
        return;
      }
      container.innerHTML = Object.entries(data).map(([slug, comp]) => `
    <div class="company-card">
      <div class="company-header" onclick="showHistory('company', '${slug}', '${comp.company.name}')">
        <div class="company-dot" style="background:${comp.company.color}"></div>
        <div class="company-name">${comp.company.name}</div>
        <small style="color:var(--muted)">${comp.draws.length} sorteos ❯</small>
      </div>
      <div class="draws-grid">
        ${comp.draws.map(d => `
          <div class="draw-block" onclick="event.stopPropagation(); showHistory('draw', '${slug}', '${d.drawName}')">
            <div class="draw-name">${d.drawName}</div>
            ${d.drawTime ? `<div class="draw-time">⏰ ${d.drawTime}</div>` : ''}
            <div class="numbers-row">
              ${(d.numbers || []).map((n, i) => {
        const t = (d.numberTypes || [])[i] || 'normal';
        return `<div class="num-ball num-${t}">${String(n).padStart(2, '0')}</div>`;
      }).join('')}
            </div>
          </div>`).join('')}
      </div>
    </div>`).join('');
    }

    // Calendario
    function openCalendar() {
      document.getElementById('calendarInput').showPicker();
    }

    function onCalendarSelect(val) {
      if (!val) return;
      loadResults(val);
    }

    // Historial Modales
    async function showHistory(type, slug, name) {
      const overlay = document.getElementById('modalOverlay');
      const title = document.getElementById('modalTitle');
      const body = document.getElementById('modalBody');

      title.textContent = `Historial: ${name}`;
      body.innerHTML = '<div style="padding:40px; text-align:center; opacity:0.5">Cargando...</div>';
      overlay.style.display = 'flex';

      try {
        const action = type === 'company' ? `company=${slug}` : `draw=${encodeURIComponent(name)}`;
        const r = await fetch(`api.php?action=history&${action}`);
        const d = await r.json();

        if (d.success && d.data.length > 0) {
          let html = '';
          d.data.forEach(item => {
            const dateF = formatDate(item.draw_date);
            html += `
                <div class="history-item">
                    <div style="display:flex; justify-content:space-between; margin-bottom:10px">
                        <span style="font-size:0.75rem; color:var(--muted); font-weight:700">${dateF}</span>
                        <span style="font-size:0.75rem; color:var(--p); font-weight:700">${item.draw_name}</span>
                    </div>
                    <div class="numbers-row">
                        ${item.numbers.map((n, i) => {
              const t = (item.number_types || [])[i] || 'normal';
              return `<div class="num-ball num-${t}" style="width:30px; height:30px; font-size:0.75rem">${String(n).padStart(2, '0')}</div>`;
            }).join('')}
                    </div>
                </div>`;
          });
          body.innerHTML = html;
        } else {
          body.innerHTML = '<div style="padding:40px; text-align:center; color:var(--muted)">No se encontró historial reciente.</div>';
        }
      } catch (e) {
        body.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444">Error al cargar datos.</div>';
      }
    }

    function closeModal() {
      document.getElementById('modalOverlay').style.display = 'none';
    }

    function formatDate(dStr) {
      const [y, m, day] = dStr.split('-');
      return `${day}/${m}/${y}`;
    }

    // Auto-refresh inteligente en tiempo real
    let currentDbCount = <?= $db->countResults() ?>;
    let currentLastDate = '<?= htmlspecialchars($state['last_processed_date'] ?? 'N/A') ?>';

    setInterval(async () => {
      try {
        const r = await fetch('api.php?action=stats');
        const d = await r.json();
        if (d.success) {
          const newCount = d.stats.total_results;
          const newLastDate = d.stats.last_date;

          let formattedLastDate = 'N/A';
          if (newLastDate !== 'N/A') {
            formattedLastDate = formatDate(newLastDate);
          }

          // Actualizar contadores
          if (document.getElementById('stat-count').textContent !== new Intl.NumberFormat().format(newCount)) {
            document.getElementById('stat-count').textContent = new Intl.NumberFormat().format(newCount);
          }
          if (document.getElementById('stat-date').textContent !== formattedLastDate) {
            document.getElementById('stat-date').textContent = formattedLastDate;
          }

          if (newCount !== currentDbCount || newLastDate !== currentLastDate) {
            currentDbCount = newCount;
            currentLastDate = newLastDate;
            loadResults(newLastDate);
          }
        }
      } catch (e) { }
    }, 5000); // Revisa silenciosamente cada 5 segundos
  </script>
</body>

</html>