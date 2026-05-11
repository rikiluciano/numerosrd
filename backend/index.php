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
  <title>LotteryApp – Dashboard</title>
  <meta name="description" content="Sistema inteligente de extracción de resultados de loterías dominicanas con IA">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
    rel="stylesheet">
  <style>
    :root {
      --bg: #0A0A0A;
      --card: #141414;
      --card2: #1C1C1C;
      --text: #EDEDED;
      --muted: #A0A0A0;
      --border: #2E2E2E;
      --p: #3291FF;
      --r: 20px;
      --shadow: 0 8px 30px rgba(0, 0, 0, 0.5);
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      background-color: var(--bg);
      color: var(--text);
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      margin: 0;
      padding: 0;
      min-height: 100vh;
      -webkit-font-smoothing: antialiased;
    }

    .app-container {
      max-width: 600px;
      margin: 0 auto;
      padding: 0;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    .topbar {
      background: rgba(10, 10, 10, 0.75);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border-bottom: 1px solid var(--border);
      padding: 16px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 100;
      gap: 12px;
    }

    .topbar-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      font-weight: 800;
      font-size: 1.25rem;
      letter-spacing: -0.5px;
      background: linear-gradient(135deg, #ffffff, #a0a0a0);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .topbar-brand span {
      font-size: 1.6rem;
      -webkit-text-fill-color: initial;
    }

    .badge-mode {
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .5px;
      text-transform: uppercase;
    }

    .badge-realtime {
      background: rgba(52, 211, 153, 0.15);
      color: #34d399;
      border: 1px solid rgba(52, 211, 153, 0.3);
    }

    .badge-historical {
      background: rgba(251, 191, 36, 0.15);
      color: #fbbf24;
      border: 1px solid rgba(251, 191, 36, 0.3);
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 16px;
      padding: 24px;
    }

    .stat-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--r);
      padding: 20px;
      text-align: center;
      transition: transform .2s, box-shadow .2s;
      cursor: pointer;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    .stat-card:hover {
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
    }

    .stat-card:active {
      transform: scale(0.97);
    }

    .stat-val {
      font-size: 1.7rem;
      font-weight: 800;
      background: linear-gradient(135deg, #fff, #888);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      letter-spacing: -0.5px;
    }

    .stat-lbl {
      font-size: 11px;
      color: var(--muted);
      margin-top: 6px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .section {
      padding: 0 24px 24px;
      flex: 1;
    }

    .section-title {
      font-size: .85rem;
      font-weight: 700;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 1px;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .date-clickable {
      cursor: pointer;
      background: rgba(255, 255, 255, 0.05);
      padding: 6px 12px;
      border-radius: 8px;
      transition: all .2s;
      border: 1px solid transparent;
      color: #fff;
    }

    .date-clickable:hover {
      background: rgba(255, 255, 255, 0.1);
      border-color: var(--border);
    }

    .companies-wrap {
      display: flex;
      flex-direction: column;
      gap: 20px;
      transition: opacity .3s ease-out;
    }

    .company-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--r);
      overflow: hidden;
      box-shadow: var(--shadow);
    }

    .company-header {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 16px 20px;
      background: var(--card2);
      border-bottom: 1px solid var(--border);
      cursor: pointer;
      transition: background .2s;
    }

    .company-header:hover {
      background: rgba(255, 255, 255, 0.03);
    }

    .company-dot {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      flex-shrink: 0;
      box-shadow: 0 0 10px rgba(255, 255, 255, 0.2);
    }

    .draw-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 4px;
    }

    .draw-logo {
      max-width: 60px;
      max-height: 25px;
      object-fit: contain;
      margin-left: 8px;
    }

    .draw-name {
      font-weight: 700;
      font-size: 1.05rem;
      flex: 1;
      letter-spacing: -0.3px;
    }

    .draws-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      gap: 1px;
      background: var(--border);
    }

    .draw-block {
      background: var(--card2);
      padding: 20px;
      cursor: pointer;
      transition: background .2s;
    }

    .draw-block:hover {
      background: var(--card);
    }

    .draw-name {
      font-size: .95rem;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 8px;
    }

    .draw-time {
      font-size: .75rem;
      color: var(--muted);
      font-weight: 500;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .numbers-row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .num-ball {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 1rem;
      flex-shrink: 0;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4), inset 0 1px 1px rgba(255, 255, 255, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.05);
      transition: transform 0.2s;
    }

    .num-ball:hover {
      transform: scale(1.1);
    }

    .num-special1 {
      background: linear-gradient(135deg, #FFD700, #F59E0B);
      color: #451A03;
      box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4), inset 0 2px 2px rgba(255, 255, 255, 0.5);
    }

    .num-special2 {
      background: linear-gradient(135deg, #38BDF8, #3B82F6);
      color: #FFFFFF;
      box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4), inset 0 2px 2px rgba(255, 255, 255, 0.3);
    }

    .num-special3 {
      background: linear-gradient(135deg, #34D399, #10B981);
      color: #022C22;
      box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4), inset 0 2px 2px rgba(255, 255, 255, 0.4);
    }

    .num-normal {
      background: linear-gradient(135deg, #27272A, #18181B);
      color: #FFFFFF;
    }

    .empty {
      text-align: center;
      padding: 80px 20px;
      color: var(--muted);
    }

    .empty-icon {
      font-size: 3.5rem;
      margin-bottom: 20px;
      opacity: 0.6;
    }

    #toast {
      position: fixed;
      bottom: 30px;
      left: 50%;
      transform: translateX(-50%);
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 30px;
      padding: 14px 28px;
      font-size: 14px;
      font-weight: 600;
      z-index: 9999;
      opacity: 0;
      transition: opacity .3s;
      pointer-events: none;
      box-shadow: var(--shadow);
    }

    #toast.show {
      opacity: 1;
    }

    .ai-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(50, 145, 255, 0.1);
      border: 1px solid rgba(50, 145, 255, 0.2);
      border-radius: 20px;
      padding: 6px 14px;
      font-size: 11px;
      color: var(--p);
      font-weight: 700;
    }

    .ai-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: var(--p);
      box-shadow: 0 0 10px var(--p);
      animation: pulse 2s infinite;
    }

    @keyframes pulse {

      0%,
      100% {
        opacity: 1;
      }

      50% {
        opacity: .4;
      }
    }

    .fade-out {
      opacity: 0;
    }

    #calendarInput {
      position: absolute;
      visibility: hidden;
      top: 0;
      left: 0;
    }

    /* Modal Styles */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.85);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 10000;
      padding: 20px;
    }

    .modal-content {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--r);
      width: 100%;
      max-width: 500px;
      max-height: 85vh;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      box-shadow: var(--shadow);
    }

    .modal-header {
      padding: 20px 24px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: var(--card2);
    }

    .modal-body {
      overflow-y: auto;
      padding: 10px 0;
    }

    .modal-close {
      cursor: pointer;
      font-size: 1.5rem;
      opacity: 0.5;
      line-height: 1;
      transition: opacity 0.2s;
    }

    .modal-close:hover {
      opacity: 1;
    }

    .history-item {
      padding: 20px 24px;
      border-bottom: 1px solid var(--border);
      animation: slideIn .4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    .history-item:last-child {
      border-bottom: none;
    }

    @keyframes slideIn {
      from {
        opacity: 0;
        transform: translateY(15px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* Minimalist Footer */
    .app-footer {
      padding: 40px 24px 30px;
      text-align: center;
      margin-top: auto;
    }

    .footer-content {
      display: inline-flex;
      align-items: center;
      gap: 12px;
      font-size: 0.8rem;
      color: var(--muted);
      background: var(--card);
      padding: 10px 24px;
      border-radius: 30px;
      border: 1px solid var(--border);
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
    }

    .footer-content strong {
      background: linear-gradient(135deg, #ffffff, #a0a0a0);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      font-weight: 800;
      letter-spacing: 0.5px;
    }

    .footer-dot {
      color: var(--p);
      font-size: 1.2rem;
      line-height: 0;
    }

    @media(max-width:480px) {
      .draws-grid {
        grid-template-columns: 1fr;
      }

      .footer-content {
        flex-direction: column;
        gap: 6px;
        padding: 14px 24px;
      }

      .footer-dot {
        display: none;
      }
    }
  </style>
</head>

<body>

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-brand"><span>🎰</span>LotteryApp</div>
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
          renderResults(d.data || {}, container, date);
        }
      } catch (e) {
        toast('❌ Error de conexión', false);
      } finally {
        container.classList.remove('fade-out');
      }
    }

    const getLogo = (companySlug, drawName) => {
        if (companySlug === 'leidsa' && drawName === 'Loto Pool') return 'https://cdn-lottery.kiskoo.com/b9f95bbf6087f617f58efb5078ca5898.png';
        let filename = '';
        if (companySlug === 'loto-real' && drawName === 'Loto Pool') filename = 'loto pool real';
        else if (drawName === 'Juega + Pega +') filename = 'juega-mas-pega-mas';
        else if (drawName === 'Gana Más') filename = 'gana-mas';
        else if (drawName === 'Lotería Nacional') filename = 'loteria-nacional';
        else if (drawName === 'Pega 3 Más') filename = 'pega-3-mas';
        else if (drawName === 'Quiniela Leidsa') filename = 'quiniela-leidsa';
        else if (drawName === 'Super Kino TV') filename = 'super-kino';
        else if (drawName === 'Loto - Super Loto Más') filename = 'loto-leidsa';
        else if (drawName === 'Quiniela Real') filename = 'loteria-real';
        else if (drawName === 'Loto Real') filename = 'loto-real';
        else if (drawName === 'Loto Pool Noche') filename = 'loto-pool-noche';
        else if (drawName === 'Quiniela Loteka') filename = 'quiniela-loteka';
        else if (drawName === 'Mega Chances') filename = 'mega-chances';
        else if (drawName === 'MegaLotto') filename = 'mega-lotto-loteka';
        else if (drawName === 'Florida Día') filename = 'florida-dia';
        else if (drawName === 'Florida Noche') filename = 'florida-noche';
        else if (drawName === 'New York Tarde') filename = 'new-york-tarde';
        else if (drawName === 'New York Noche') filename = 'new-york-noche';
        else if (drawName === 'La Primera Día') filename = 'la-primera-dia';
        else if (drawName === 'Primera Noche') filename = 'la-primera-noche';
        else if (drawName.includes('La Suerte')) filename = 'la-suerte-dominicana';
        else if (drawName === 'Quiniela LoteDom') filename = 'quiniela-lotedom';
        else if (drawName === 'El Quemaito Mayor') filename = 'el-quemaito-mayor';
        else if (drawName.includes('Anguila')) filename = 'anguila-lottery';
        else if (drawName.includes('King Lottery')) filename = 'king-lottery';
        return filename ? `https://cdn-lottery.kiskoo.com/loterias-dominicanas/${filename}.png` : '';
    };

    function renderResults(data, container, fullDateStr) {
      if (!Object.keys(data).length) {
        container.innerHTML = '<div class="empty"><div class="empty-icon">📭</div><p>Sin resultados para esta fecha</p></div>';
        return;
      }
      
      const p = (fullDateStr || '').split('-');
      const formattedDate = p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : fullDateStr;

      container.innerHTML = Object.entries(data).map(([slug, comp]) => `
    <div class="company-card">
      <div class="company-header" onclick="showHistory('company', '${slug}', '${comp.company.name}')">
        <div class="company-dot" style="background:${comp.company.color}"></div>
        <div class="company-name">${comp.company.name}</div>
        <small style="color:var(--muted)">${comp.draws.length} sorteos ❯</small>
      </div>
      <div class="draws-grid">
        ${comp.draws.map(d => {
          const logoUrl = getLogo(slug, d.drawName);
          const logoHtml = logoUrl ? `<img src="${logoUrl}" class="draw-logo" alt="logo" loading="lazy">` : '';
          return `
          <div class="draw-block" onclick="event.stopPropagation(); showHistory('draw', '${slug}', '${d.drawName}')">
            <div class="draw-header">
              <div class="draw-name">${d.drawName}</div>
              ${logoHtml}
            </div>
            <div class="draw-time">📅 ${formattedDate}</div>
            <div class="numbers-row">
              ${(d.numbers || []).map((n, i) => {
        const colors = ['special1', 'special2', 'special3', 'normal', 'normal', 'normal'];
        let t = (d.numberTypes || [])[i];
        if (!t || t === 'normal') t = colors[i % colors.length];
        return \`<div class="num-ball num-\${t}">\${String(n).padStart(2, '0')}</div>\`;
      }).join('')}
            </div>
          </div>`;
        }).join('')}
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
  <footer class="app-footer">
    <div class="footer-content">
      <span>Desarrollado por <strong>RLabs</strong></span>
      <span class="footer-dot">•</span>
      <span>&copy; <?= date('Y') ?> Todos los derechos reservados</span>
    </div>
  </footer>
</body>

</html>