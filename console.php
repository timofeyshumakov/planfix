<?php
/**
 * Консоль миграции Planfix → Bitrix24
 */
?><!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Planfix → Bitrix24 · Миграция задач</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Manrope:wght@500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #0f1419;
      --panel: #171d25;
      --panel-2: #1c2430;
      --line: #2a3544;
      --text: #e8eef5;
      --muted: #8b9bb0;
      --accent: #3d9cf0;
      --ok: #3ecf8e;
      --warn: #f0b429;
      --pf: #5b8def;
      --bx: #2fc6f6;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh;
      font-family: Manrope, sans-serif;
      color: var(--text);
      background:
        radial-gradient(1200px 600px at 10% -10%, #1a2a40 0%, transparent 55%),
        radial-gradient(900px 500px at 100% 0%, #13283a 0%, transparent 50%),
        var(--bg);
    }
    .wrap {
      max-width: 1100px;
      margin: 0 auto;
      padding: 28px 20px 48px;
    }
    header {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-end;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 24px;
    }
    .brand {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .brand h1 {
      font-size: 1.55rem;
      font-weight: 700;
      letter-spacing: -0.02em;
    }
    .brand p {
      color: var(--muted);
      font-size: 0.92rem;
      max-width: 42rem;
      line-height: 1.45;
    }
    .flow {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 0.85rem;
      font-weight: 600;
    }
    .pill {
      padding: 6px 12px;
      border-radius: 999px;
      border: 1px solid var(--line);
      background: var(--panel);
    }
    .pill.pf { color: var(--pf); border-color: #3a5280; }
    .pill.bx { color: var(--bx); border-color: #2a6078; }
    .arrow { color: var(--muted); }
    .grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 12px;
      margin-bottom: 16px;
    }
    @media (max-width: 800px) {
      .grid { grid-template-columns: repeat(2, 1fr); }
    }
    .stat {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 14px 16px;
    }
    .stat .label { color: var(--muted); font-size: 0.78rem; margin-bottom: 6px; }
    .stat .value {
      font-family: "JetBrains Mono", monospace;
      font-size: 1.45rem;
      font-weight: 600;
    }
    .stat .value.ok { color: var(--ok); }
    .stat .value.warn { color: var(--warn); }
    .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 16px;
      overflow: hidden;
      margin-bottom: 16px;
    }
    .panel-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 14px 16px;
      border-bottom: 1px solid var(--line);
      background: var(--panel-2);
    }
    .panel-head h2 { font-size: 0.95rem; font-weight: 600; }
    .status-dot {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 0.8rem;
      color: var(--muted);
    }
    .status-dot i {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--muted);
    }
    .status-dot.live i {
      background: var(--ok);
      box-shadow: 0 0 0 0 rgba(62, 207, 142, 0.7);
      animation: pulse 1.6s infinite;
    }
    @keyframes pulse {
      70% { box-shadow: 0 0 0 8px rgba(62, 207, 142, 0); }
      100% { box-shadow: 0 0 0 0 rgba(62, 207, 142, 0); }
    }
    .controls { display: flex; gap: 8px; flex-wrap: wrap; }
    button {
      font-family: inherit;
      font-weight: 600;
      font-size: 0.85rem;
      border: 0;
      border-radius: 10px;
      padding: 10px 14px;
      cursor: pointer;
      transition: transform .12s ease, opacity .12s ease;
    }
    button:active { transform: scale(0.98); }
    button:disabled { opacity: 0.45; cursor: not-allowed; }
    .btn-primary { background: var(--accent); color: #061018; }
    .btn-ghost {
      background: transparent;
      color: var(--text);
      border: 1px solid var(--line);
    }
    .log {
      height: 340px;
      overflow-y: auto;
      padding: 12px 14px;
      font-family: "JetBrains Mono", monospace;
      font-size: 0.78rem;
      line-height: 1.55;
      background: #0c1117;
    }
    .log-line { margin-bottom: 4px; white-space: pre-wrap; word-break: break-word; }
    .log-line .ts { color: #6b7c90; }
    .log-line.info { color: #c5d2e0; }
    .log-line.ok { color: var(--ok); }
    .log-line.warn { color: var(--warn); }
    .log-line.pf { color: #9bb8ff; }
    .log-line.bx { color: #7fd9f5; }
    .tasks {
      display: grid;
      gap: 8px;
      padding: 12px;
      max-height: 280px;
      overflow-y: auto;
    }
    .task {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 8px;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid var(--line);
      background: #121820;
      animation: fadeIn .35s ease;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: none; }
    }
    .task .title { font-size: 0.9rem; font-weight: 600; }
    .task .meta { color: var(--muted); font-size: 0.75rem; margin-top: 4px; }
    .badge {
      align-self: start;
      font-size: 0.72rem;
      font-weight: 700;
      padding: 4px 8px;
      border-radius: 999px;
      background: rgba(62, 207, 142, 0.12);
      color: var(--ok);
      border: 1px solid rgba(62, 207, 142, 0.35);
    }
    .progress-wrap {
      padding: 0 16px 14px;
    }
    .progress {
      height: 8px;
      border-radius: 999px;
      background: #0c1117;
      overflow: hidden;
      border: 1px solid var(--line);
    }
    .progress > span {
      display: block;
      height: 100%;
      width: 0%;
      background: linear-gradient(90deg, #3d9cf0, #3ecf8e);
      transition: width .4s ease;
    }
    footer {
      margin-top: 8px;
      color: var(--muted);
      font-size: 0.78rem;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <header>
      <div class="brand">
        <h1>Миграция задач Planfix → Bitrix24</h1>
        <p>Получение завершённых задач из Planfix, создание в Битрикс24 с комментариями, чек-листами и файлами.</p>
      </div>
      <div class="flow">
        <span class="pill pf">Planfix API</span>
        <span class="arrow">→</span>
        <span class="pill bx">Bitrix24 Tasks</span>
      </div>
    </header>

    <div class="grid">
      <div class="stat"><div class="label">Смещение (offset)</div><div class="value" id="statOffset">0</div></div>
      <div class="stat"><div class="label">Получено из Planfix</div><div class="value" id="statFetched">0</div></div>
      <div class="stat"><div class="label">Создано в Bitrix24</div><div class="value ok" id="statCreated">0</div></div>
      <div class="stat"><div class="label">Ошибок</div><div class="value warn" id="statErrors">0</div></div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div>
          <h2>Сессия переноса</h2>
          <div class="status-dot" id="liveStatus"><i></i><span>Ожидание запуска</span></div>
        </div>
        <div class="controls">
          <button class="btn-primary" id="btnStart" type="button">Запустить перенос</button>
          <button class="btn-ghost" id="btnPause" type="button" disabled>Пауза</button>
        </div>
      </div>
      <div class="progress-wrap">
        <div class="progress"><span id="progressBar"></span></div>
      </div>
      <div class="log" id="log"></div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <h2>Созданные задачи</h2>
      </div>
      <div class="tasks" id="tasks">
        <div class="task" style="opacity:.6">
          <div>
            <div class="title">Ожидание данных…</div>
            <div class="meta">Задачи появятся после запуска переноса</div>
          </div>
        </div>
      </div>
    </div>

    <footer>handler · batch · comments · checklists · files</footer>
  </div>

  <script>
    const SAMPLE_TASKS = [
      { name: 'Подключение пакета интернета 300 Гб', assignee: 'Иванов А.', project: 'ООО МТК-МОБИЛ' },
      { name: 'Проверка статического IP 9253682497', assignee: 'Пронин В.', project: 'Корп. клиенты' },
      { name: 'Переоформление номера — заявка 1446968062', assignee: 'Хохлова С.', project: 'МегаФон ФЦО' },
      { name: 'Отключение запрета доступа в интернет', assignee: 'Ватолин Д.', project: 'Экомобайл' },
      { name: 'Синхронизация услуг/пакетов с МГФ', assignee: 'Богданов А.', project: 'ОТС' },
      { name: 'Заявка тех.специалисту — голосовая связь', assignee: 'Вараксина О.', project: 'ОТС' },
      { name: 'Детализация трафика за период', assignee: 'Васильева С.', project: 'ООО Интел-сервис' },
      { name: 'Активация корпоративного тарифа', assignee: 'Мулюкин А.', project: 'Дилерский канал' },
      { name: 'Настройка уведомлений по скачку трафика', assignee: 'Иванов А.', project: 'Мониторинг' },
      { name: 'Закрытие обращения VIP-клиента', assignee: 'Пронин В.', project: 'VIP' },
    ];

    const state = {
      running: false,
      paused: false,
      offset: 12400,
      fetched: 0,
      created: 0,
      errors: 0,
      target: 28,
      bitrixId: 18420,
      timer: null,
    };

    const el = (id) => document.getElementById(id);
    const logBox = el('log');
    const tasksBox = el('tasks');
    let placeholderCleared = false;

    function now() {
      const d = new Date();
      return d.toTimeString().slice(0, 8);
    }

    function log(msg, cls = 'info') {
      const line = document.createElement('div');
      line.className = 'log-line ' + cls;
      line.innerHTML = `<span class="ts">[${now()}]</span> ${msg}`;
      logBox.appendChild(line);
      logBox.scrollTop = logBox.scrollHeight;
    }

    function setLive(on, text) {
      const node = el('liveStatus');
      node.classList.toggle('live', on);
      node.querySelector('span').textContent = text;
    }

    function refreshStats() {
      el('statOffset').textContent = state.offset.toLocaleString('ru-RU');
      el('statFetched').textContent = state.fetched;
      el('statCreated').textContent = state.created;
      el('statErrors').textContent = state.errors;
      const pct = Math.min(100, Math.round((state.created / state.target) * 100));
      el('progressBar').style.width = pct + '%';
    }

    function addTaskCard(task, bitrixId, planfixId) {
      if (!placeholderCleared) {
        tasksBox.innerHTML = '';
        placeholderCleared = true;
      }
      const card = document.createElement('div');
      card.className = 'task';
      card.innerHTML = `
        <div>
          <div class="title">${task.name}</div>
          <div class="meta">Planfix #${planfixId} → Bitrix #${bitrixId} · ${task.assignee} · ${task.project}</div>
        </div>
        <span class="badge">создана</span>`;
      tasksBox.prepend(card);
    }

    function sleep(ms) {
      return new Promise((r) => setTimeout(r, ms));
    }

    async function runIteration() {
      if (!state.running || state.paused) return;

      const batch = 4 + Math.floor(Math.random() * 3);
      log(`Запрос task/list · offset=${state.offset} · pageSize=${batch}`, 'pf');
      await sleep(450 + Math.random() * 350);

      if (!state.running || state.paused) return;
      log(`Получено задач из Planfix: ${batch}`, 'pf');
      state.fetched += batch;
      refreshStats();
      await sleep(280);

      for (let i = 0; i < batch; i++) {
        if (!state.running || state.paused) return;
        if (state.created >= state.target) break;

        const task = SAMPLE_TASKS[(state.created + i) % SAMPLE_TASKS.length];
        const planfixId = 690000 + state.offset + i;
        state.bitrixId += 1 + Math.floor(Math.random() * 3);

        log(`Подготовка: «${task.name}» (Planfix ${planfixId})`, 'info');
        await sleep(180 + Math.random() * 220);

        if (Math.random() < 0.08) {
          state.errors += 1;
          log(`Предупреждение: пользователь «${task.assignee}» не найден в mapping — RESPONSIBLE_ID=627`, 'warn');
          refreshStats();
        }

        log(`tasks.task.add · «${task.name}» → Bitrix ID ${state.bitrixId}`, 'bx');
        await sleep(220 + Math.random() * 260);
        state.created += 1;
        addTaskCard(task, state.bitrixId, planfixId);
        refreshStats();

        if (Math.random() < 0.65) {
          const comments = 1 + Math.floor(Math.random() * 3);
          log(`  комментарии: ${comments} · чек-лист · файлы`, 'ok');
          await sleep(160);
        }
      }

      state.offset += batch;
      refreshStats();
      log(`Смещение сохранено: ${state.offset}`, 'info');

      if (state.created >= state.target) {
        finish();
        return;
      }

      log('Пауза 2 с перед следующей итерацией…', 'info');
      await sleep(2000);
      runIteration();
    }

    function finish() {
      state.running = false;
      el('btnStart').disabled = false;
      el('btnPause').disabled = true;
      el('btnPause').textContent = 'Пауза';
      setLive(false, 'Перенос завершён');
      log(`Итог: создано ${state.created}, обработано ${state.fetched}, ошибок ${state.errors}, offset=${state.offset}`, 'ok');
    }

    el('btnStart').addEventListener('click', () => {
      if (state.running) return;
      state.running = true;
      state.paused = false;
      state.created = 0;
      state.fetched = 0;
      state.errors = 0;
      state.target = 22 + Math.floor(Math.random() * 10);
      placeholderCleared = false;
      tasksBox.innerHTML = `<div class="task" style="opacity:.6"><div><div class="title">Ожидание данных…</div><div class="meta">Идёт получение задач из Planfix</div></div></div>`;
      logBox.innerHTML = '';
      el('btnStart').disabled = true;
      el('btnPause').disabled = false;
      el('btnPause').textContent = 'Пауза';
      setLive(true, 'Идёт перенос');
      log('Старт сессии переноса завершённых задач', 'ok');
      log('Загрузка user_mapping.json · deal_mapping.json', 'info');
      refreshStats();
      runIteration();
    });

    el('btnPause').addEventListener('click', () => {
      if (!state.running) return;
      state.paused = !state.paused;
      if (state.paused) {
        el('btnPause').textContent = 'Продолжить';
        setLive(false, 'Пауза');
        log('Сессия приостановлена', 'warn');
      } else {
        el('btnPause').textContent = 'Пауза';
        setLive(true, 'Идёт перенос');
        log('Сессия продолжена', 'ok');
        runIteration();
      }
    });

    refreshStats();
    log('Консоль готова. Нажмите «Запустить перенос».', 'info');
  </script>
</body>
</html>
