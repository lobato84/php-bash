<?php
session_start();
mb_internal_encoding('UTF-8');

// Usuarios demo (cámbialos)
$users = [
  'admin' => password_hash('adminpass', PASSWORD_DEFAULT),
  'dev'   => password_hash('devpass',   PASSWORD_DEFAULT),
];

// Login
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='login'){
  $u = $_POST['username'] ?? '';
  $p = $_POST['password'] ?? '';
  if (!isset($users[$u]) || !password_verify($p, $users[$u])) {
    $_SESSION['login_err'] = 'Usuario/contraseña incorrectos';
    header('Location: '.$_SERVER['PHP_SELF']); exit;
  }
  $_SESSION['user'] = $u;
  header('Location: '.$_SERVER['PHP_SELF']); exit;
}
if (isset($_GET['action']) && $_GET['action']==='logout'){
  session_unset(); session_destroy();
  header('Location: '.$_SERVER['PHP_SELF']); exit;
}
$user = $_SESSION['user'] ?? null;
?>
<!doctype html>
<html lang="es" class="dark">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Terminal — Pestañas, Guardar, Favoritos, Búsqueda</title>

  <!-- TailwindCSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          fontFamily: { mono: ['Consolas','Liberation Mono','Menlo','Monaco','Courier New','monospace'] }
        }
      }
    }
  </script>

  <!-- xterm.js -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm/css/xterm.css">
  <script src="https://cdn.jsdelivr.net/npm/xterm/lib/xterm.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit/lib/xterm-addon-fit.js"></script>

  <style>
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
  </style>
</head>
<body class="h-screen flex flex-col bg-zinc-50 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-200 font-sans">
  <!-- Topbar -->
  <header class="flex items-center justify-between px-4 py-2 bg-zinc-100 dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-800">
    <div class="text-sm"><span class="font-semibold">Terminal</span> <span class="text-zinc-500 dark:text-zinc-400">— PHP + PTY (bash) con pestañas</span></div>
    <div class="flex items-center gap-2">
      <button id="btnTheme" class="inline-flex items-center rounded-lg bg-zinc-200 hover:bg-zinc-300 dark:bg-zinc-800 dark:hover:bg-zinc-700 px-3 py-1.5 text-sm">Tema</button>
      <?php if ($user): ?>
        <span class="text-zinc-500 dark:text-zinc-400 mr-2">Usuario: <?=htmlspecialchars($user)?></span>
        <a href="?action=logout" class="inline-flex items-center rounded-lg bg-blue-600 hover:bg-blue-500 px-3 py-1.5 text-sm text-white">Salir</a>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($user): ?>
    <!-- Tabs bar -->
    <div id="tabsBar" class="flex items-center gap-2 px-4 py-2 bg-zinc-50 dark:bg-zinc-950 border-b border-zinc-200 dark:border-zinc-900">
      <div id="tabsList" class="flex items-center gap-2 overflow-x-auto no-scrollbar whitespace-nowrap flex-1"></div>
      <div class="flex items-center gap-2">
        <button id="btnDupTab" class="inline-flex items-center rounded-lg bg-zinc-200 hover:bg-zinc-300 dark:bg-zinc-800 dark:hover:bg-zinc-700 px-3 py-1.5 text-sm">Duplicar</button>
        <button id="btnNewTab" class="inline-flex items-center rounded-lg bg-zinc-200 hover:bg-zinc-300 dark:bg-zinc-800 dark:hover:bg-zinc-700 px-3 py-1.5 text-sm">Nueva</button>
      </div>
    </div>

    <!-- Toolbar -->
    <div id="toolbar" class="flex items-center gap-2 px-4 py-2 bg-zinc-50 dark:bg-zinc-950 border-b border-zinc-200 dark:border-zinc-900 text-xs text-zinc-600 dark:text-zinc-400">
      <button id="btnSave" class="inline-flex items-center rounded bg-zinc-200 hover:bg-zinc-300 dark:bg-zinc-800 dark:hover:bg-zinc-700 px-2.5 py-1">Guardar .txt</button>
      <button id="btnSaveHtml" class="inline-flex items-center rounded bg-zinc-200 hover:bg-zinc-300 dark:bg-zinc-800 dark:hover:bg-zinc-700 px-2.5 py-1">Guardar .html</button>
      <button id="btnCopy" class="inline-flex items-center rounded bg-zinc-200 hover:bg-zinc-300 dark:bg-zinc-800 dark:hover:bg-zinc-700 px-2.5 py-1">Copiar selección</button>
      <button id="btnClear" class="inline-flex items-center rounded bg-zinc-200 hover:bg-zinc-300 dark:bg-zinc-800 dark:hover:bg-zinc-700 px-2.5 py-1">Limpiar</button>
      <button id="btnReconnect" class="inline-flex items-center rounded bg-zinc-200 hover:bg-zinc-300 dark:bg-zinc-800 dark:hover:bg-zinc-700 px-2.5 py-1">Reconectar</button>
      <div class="mx-3 flex items-center gap-1">
        <button id="btnFontDec" class="inline-flex items-center rounded bg-zinc-200 hover:bg-zinc-300 dark:bg-zinc-800 dark:hover:bg-zinc-700 px-2.5 py-1">A−</button>
        <button id="btnFontInc" class="inline-flex items-center rounded bg-zinc-200 hover:bg-zinc-300 dark:bg-zinc-800 dark:hover:bg-zinc-700 px-2.5 py-1">A+</button>
      </div>
      <!-- Buscador -->
      <div class="flex items-center gap-1">
        <input id="findInput" class="px-2 py-1 rounded bg-zinc-100 border border-zinc-200 text-xs focus:outline-none focus:ring-1 focus:ring-blue-600 dark:bg-zinc-900 dark:border-zinc-700" placeholder="Buscar (Ctrl+F)"/>
        <button id="btnFind" class="inline-flex items-center rounded bg-zinc-200 hover:bg-zinc-300 dark:bg-zinc-800 dark:hover:bg-zinc-700 px-2 py-1">Ir</button>
        <button id="btnFindClear" class="inline-flex items-center rounded bg-zinc-200 hover:bg-zinc-300 dark:bg-zinc-800 dark:hover:bg-zinc-700 px-2 py-1">Limpiar</button>
      </div>
      <!-- Favoritos -->
      <div class="ml-3 flex items-center gap-1">
        <select id="favSelect" class="px-2 py-1 rounded bg-zinc-100 border border-zinc-200 text-xs dark:bg-zinc-900 dark:border-zinc-700"></select>
        <button id="btnRunFav" class="inline-flex items-center rounded bg-emerald-600 hover:bg-emerald-500 px-2 py-1 text-white">Ejecutar</button>
        <button id="btnEditFav" class="inline-flex items-center rounded bg-zinc-200 hover:bg-zinc-300 dark:bg-zinc-800 dark:hover:bg-zinc-700 px-2 py-1">Editar</button>
      </div>

      <span id="status" class="ml-auto">–</span>
    </div>

    <!-- Terminal area -->
    <main id="terminalWrap" class="flex-1 p-4">
      <!-- se inyectan contenedores por pestaña -->
    </main>
  <?php else: ?>
    <!-- Login -->
    <div id="loginBox" class="fixed left-1/2 top-1/3 -translate-x-1/2 -translate-y-1/3 bg-zinc-100 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl p-6 shadow-xl">
      <form method="post" class="space-y-3">
        <input type="hidden" name="action" value="login"/>
        <div>
          <label class="block text-sm text-zinc-600 dark:text-zinc-400 mb-1">Usuario</label>
          <input name="username" class="w-64 px-3 py-2 rounded bg-zinc-50 border border-zinc-200 focus:outline-none focus:ring-1 focus:ring-blue-600 dark:bg-zinc-950 dark:border-zinc-800" autofocus>
        </div>
        <div>
          <label class="block text-sm text-zinc-600 dark:text-zinc-400 mb-1">Contraseña</label>
          <input name="password" type="password" class="w-64 px-3 py-2 rounded bg-zinc-50 border border-zinc-200 focus:outline-none focus:ring-1 focus:ring-blue-600 dark:bg-zinc-950 dark:border-zinc-800">
        </div>
        <div class="pt-2">
          <button class="inline-flex items-center rounded-lg bg-blue-600 hover:bg-blue-500 px-4 py-2 text-sm text-white">Entrar</button>
        </div>
        <div class="text-red-500 dark:text-red-400 text-sm"><?=
          htmlspecialchars($_SESSION['login_err'] ?? '') ?></div>
      </form>
    </div>
  <?php endif; ?>

<?php if ($user): ?>
<script>
  // ====== Tema persistente ======
  const btnTheme = document.getElementById('btnTheme');
  const savedTheme = localStorage.getItem('term_theme') || 'dark';
  document.documentElement.classList.toggle('dark', savedTheme === 'dark');
  btnTheme.addEventListener('click', ()=>{
    const nowDark = !document.documentElement.classList.contains('dark');
    document.documentElement.classList.toggle('dark', nowDark);
    localStorage.setItem('term_theme', nowDark ? 'dark' : 'light');
  });

  // ====== Config WS ======
  const WS_URL = (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.hostname + ':8081/';
  // const WS_URL = (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + '/pty';

  // ====== Favoritos (localStorage) ======
  const favSelect = document.getElementById('favSelect');
  const btnRunFav = document.getElementById('btnRunFav');
  const btnEditFav = document.getElementById('btnEditFav');

  function loadFavs(){
    let favs = [];
    try { favs = JSON.parse(localStorage.getItem('term_favs')||'[]'); } catch(_){}
    if (!Array.isArray(favs) || favs.length===0) {
      favs = ['ls -la', 'htop', 'tail -f /var/log/syslog', 'df -h', 'free -m'];
      localStorage.setItem('term_favs', JSON.stringify(favs));
    }
    favSelect.innerHTML = '';
    favs.forEach(f=>{
      const o = document.createElement('option'); o.value=f; o.textContent=f; favSelect.appendChild(o);
    });
  }
  function editFavs(){
    let favs = [];
    try { favs = JSON.parse(localStorage.getItem('term_favs')||'[]'); } catch(_){}
    const txt = prompt('Edita tus favoritos (uno por línea):', favs.join('\n'));
    if (txt!==null) {
      const arr = txt.split('\n').map(s=>s.trim()).filter(Boolean);
      localStorage.setItem('term_favs', JSON.stringify(arr));
      loadFavs();
    }
  }
  loadFavs();
  btnEditFav.addEventListener('click', editFavs);

  // ====== Estado de pestañas ======
  let sessions = []; // {id, name, el, term, fit, socket, transcript, connected, ro, currentLine, lastCmd, fontSize, backoff}
  let activeId = null;
  let seq = 1;

  const tabsList = document.getElementById('tabsList');
  const terminalWrap = document.getElementById('terminalWrap');
  const statusEl = document.getElementById('status');

  const btnNewTab = document.getElementById('btnNewTab');
  const btnDupTab = document.getElementById('btnDupTab');
  const btnSave = document.getElementById('btnSave');
  const btnSaveHtml = document.getElementById('btnSaveHtml');
  const btnCopy = document.getElementById('btnCopy');
  const btnClear = document.getElementById('btnClear');
  const btnReconnect = document.getElementById('btnReconnect');
  const btnFontInc = document.getElementById('btnFontInc');
  const btnFontDec = document.getElementById('btnFontDec');

  const findInput = document.getElementById('findInput');
  const btnFind = document.getElementById('btnFind');
  const btnFindClear = document.getElementById('btnFindClear');

  function makeId(){ return 'tab-'+(seq++); }

  // ----- Tabs UI -----
  function renderTabs(){
    tabsList.innerHTML = '';
    sessions.forEach((s,idx)=>{
      const tab = document.createElement('button');
      tab.className =
        `inline-flex items-center gap-2 px-3 py-1.5 rounded-full border transition-colors shrink-0 select-none ` +
        `${s.id===activeId ? 'bg-zinc-200 dark:bg-zinc-800 border-zinc-300 dark:border-zinc-700' : 'bg-zinc-100 dark:bg-zinc-900 border-zinc-200 dark:border-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-800'}`;
      tab.title = s.lastCmd || s.name;
      tab.draggable = true;
      tab.dataset.id = s.id;

      // drag reorder
      tab.addEventListener('dragstart', e=>{
        e.dataTransfer.setData('text/plain', s.id);
      });
      tab.addEventListener('dragover', e=> e.preventDefault());
      tab.addEventListener('drop', e=>{
        e.preventDefault();
        const draggedId = e.dataTransfer.getData('text/plain');
        const from = sessions.findIndex(x=>x.id===draggedId);
        const to = sessions.findIndex(x=>x.id===s.id);
        if (from>-1 && to>-1 && from!==to) {
          const item = sessions.splice(from,1)[0];
          sessions.splice(to,0,item);
          renderTabs();
        }
      });

      // estado
      const dot = document.createElement('span');
      dot.className = `w-2 h-2 rounded-full ${s.connected ? 'bg-emerald-500' : 'bg-red-500'}`;

      const label = document.createElement('span');
      label.className = 'max-w-[16rem] truncate text-sm';
      label.textContent = (s.lastCmd || s.name);

      // renombrar (doble clic)
      label.ondblclick = (e)=>{
        e.stopPropagation();
        const nv = prompt('Nuevo nombre de pestaña:', s.lastCmd || s.name);
        if (nv && nv.trim()) { s.name = nv.trim(); s.lastCmd=''; renderTabs(); updateStatus(); }
      };

      const close = document.createElement('span');
      close.className = 'text-zinc-500 hover:text-red-500 ml-1 cursor-pointer';
      close.textContent = '×';
      close.onclick = (e)=>{ e.stopPropagation(); closeTab(s.id); };

      tab.appendChild(dot);
      tab.appendChild(label);
      tab.appendChild(close);
      tab.onclick = ()=>switchTab(s.id);
      tabsList.appendChild(tab);
    });
    updateStatus();
  }

  function updateStatus(){
    const s = sessions.find(x=>x.id===activeId);
    if (!s) { statusEl.textContent = '–'; return; }
    statusEl.textContent = `${s.connected ? '🟢' : '🔴'}  ${s.lastCmd || s.name} — ${s.term ? s.term.cols : '?'}×${s.term ? s.term.rows : '?'}`;
  }

  // ----- Crear sesión -----
  function createSession(dupOfId=null){
    const id = makeId();
    const name = 'Pestaña ' + (sessions.length + 1);

    const el = document.createElement('div');
    el.id = 'container-'+id;
    el.className = 'w-full h-full rounded-xl overflow-hidden border border-zinc-200 dark:border-zinc-800 shadow-inner bg-white dark:bg-zinc-950 hidden';
    terminalWrap.appendChild(el);

    const term = new Terminal({
      cursorBlink: true,
      convertEol: true,
      fontFamily: 'Consolas, "Liberation Mono", Menlo, Monaco, "Courier New", monospace',
      fontSize: 13,
      lineHeight: 1.2,
      allowTransparency: true,
      scrollback: 8000
    });
    const fit = new FitAddon.FitAddon();
    term.loadAddon(fit);
    term.open(el);

    let transcript = '';
    let currentLine = '';
    let lastCmd = '';
    let socket = null;
    let connected = false;
    let backoff = 1000; // ms

    function appendTranscript(s){
      transcript += s.replace(/\r?\n/g, '\n');
      if (transcript.length > 5_000_000) transcript = transcript.slice(-2_500_000);
    }
    function setLastCmd(cmd){
      lastCmd = (cmd||'').trim();
      const sess = sessions.find(x=>x.id===id);
      if (sess) { sess.lastCmd = lastCmd; renderTabs(); }
    }

    function connect(){
      socket = new WebSocket(WS_URL);
      socket.binaryType = 'arraybuffer';

      socket.onopen = () => {
        connected = true;
        backoff = 1000;
        term.writeln("✔ Conectado. PTY real.");
        fit.fit();
        socket.send(JSON.stringify({type:'resize', cols: term.cols, rows: term.rows}));
        term.focus();
        const sess = sessions.find(x=>x.id===id);
        if (sess) { sess.connected = true; }
        renderTabs();
      };

      socket.onmessage = (ev) => {
        if (typeof ev.data === 'string') {
          try {
            const msg = JSON.parse(ev.data);
            if (msg.type === 'notice') {
              term.writeln("\r\n" + msg.text);
              appendTranscript("\n" + msg.text + "\n");
              return;
            }
          } catch(_){}
          term.write(ev.data);
          appendTranscript(ev.data);
          return;
        }
        const text = new TextDecoder().decode(new Uint8Array(ev.data));
        term.write(text);
        appendTranscript(text);
      };

      socket.onclose = () => {
        connected = false;
        term.writeln("\r\n[Desconectado]");
        const sess = sessions.find(x=>x.id===id);
        if (sess) { sess.connected = false; }
        renderTabs();
        // auto-reconnect con backoff
        setTimeout(()=>{ if (sessions.find(x=>x.id===id)) connect(); }, backoff);
        backoff = Math.min(backoff*2, 15000);
      };

      socket.onerror = () => {
        connected = false;
        term.writeln("\r\n[Error WS]");
        const sess = sessions.find(x=>x.id===id);
        if (sess) { sess.connected = false; }
        renderTabs();
      };
    }

    term.onData(data => {
      // Captura del último comando
      for (let i=0; i<data.length; i++){
        const ch = data[i];
        if (ch === '\r' || ch === '\n') {
          if (currentLine.trim().length) setLastCmd(currentLine);
          currentLine = '';
        } else if (ch === '\x7f') { // Backspace
          currentLine = currentLine.slice(0, -1);
        } else if (ch >= ' ') {
          currentLine += ch;
        }
      }
      if (socket && socket.readyState === WebSocket.OPEN) {
        socket.send(new TextEncoder().encode(data));
        appendTranscript(data);
      }
    });

    // Resize: ResizeObserver
    const ro = new ResizeObserver(() => {
      try {
        fit.fit();
        if (socket && socket.readyState === WebSocket.OPEN) {
          socket.send(JSON.stringify({type:'resize', cols: term.cols, rows: term.rows}));
        }
        updateStatus();
      } catch(_){}
    });
    ro.observe(el);

    const session = { id, name, el, term, fit, socket, transcript, connected, ro, connect, currentLine, lastCmd, fontSize:13, backoff };
    sessions.push(session);
    connect();
    switchTab(id);

    // si duplicamos, opcionalmente copiamos título
    if (dupOfId) {
      const src = sessions.find(x=>x.id===dupOfId);
      if (src && src.lastCmd) session.name = src.lastCmd + ' (dup)';
    }
  }

  function switchTab(id){
    activeId = id;
    sessions.forEach(s=>{
      s.el.classList.toggle('hidden', s.id!==id);
      if (s.id===id) {
        setTimeout(()=>{
          try {
            s.fit.fit();
            if (s.socket && s.socket.readyState === WebSocket.OPEN) {
              s.socket.send(JSON.stringify({type:'resize', cols: s.term.cols, rows: s.term.rows}));
            }
            s.term.focus();
            updateStatus();
          } catch(_){}
        }, 0);
      }
    });
    renderTabs();
  }

  function closeTab(id){
    const idx = sessions.findIndex(s=>s.id===id);
    if (idx === -1) return;
    const s = sessions[idx];

    try { s.ro.disconnect(); } catch(_){}
    if (s.socket && (s.socket.readyState===WebSocket.OPEN || s.socket.readyState===WebSocket.CONNECTING)) {
      try { s.socket.close(); } catch(_){}
    }
    try { s.term.dispose(); } catch(_){}
    s.el.remove();
    sessions.splice(idx,1);

    if (sessions.length === 0) {
      activeId = null;
    } else {
      const next = sessions[Math.max(0, idx-1)];
      activeId = next.id;
    }
    renderTabs();
    if (activeId) switchTab(activeId);
  }

  // ----- Guardar -----
  function download(name, mime, content){
    const blob = new Blob([content], {type: mime});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    const ts = new Date().toISOString().replace(/[:.]/g,'-');
    a.download = `${name}_${ts}`;
    document.body.appendChild(a); a.click();
    setTimeout(()=>{ URL.revokeObjectURL(a.href); a.remove(); }, 0);
  }

  btnSave.addEventListener('click', ()=>{
    const s = sessions.find(x=>x.id===activeId); if (!s) return;
    download((s.lastCmd||s.name).replace(/\s+/g,'_')+'.txt', 'text/plain;charset=utf-8', s.transcript);
  });

  btnSaveHtml.addEventListener('click', ()=>{
    const s = sessions.find(x=>x.id===activeId); if (!s) return;
    const html = `<!doctype html><meta charset="utf-8"><title>${(s.lastCmd||s.name)}</title>
      <style>body{background:#0b0b0b;color:#ddd;font-family:Consolas,Menlo,monospace;white-space:pre-wrap}</style>
      <pre>${s.transcript.replace(/[&<>]/g, c=>({ '&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}</pre>`;
    download((s.lastCmd||s.name).replace(/\s+/g,'_')+'.html', 'text/html;charset=utf-8', html);
  });

  // ----- Copiar / Limpiar / Reconnect -----
  btnCopy.addEventListener('click', ()=>{
    const s = sessions.find(x=>x.id===activeId); if (!s) return;
    const sel = s.term.getSelection();
    if (sel) navigator.clipboard.writeText(sel).catch(()=>{});
  });
  btnClear.addEventListener('click', ()=>{
    const s = sessions.find(x=>x.id===activeId); if (!s) return;
    s.term.clear();
  });
  btnReconnect.addEventListener('click', ()=>{
    const s = sessions.find(x=>x.id===activeId); if (!s) return;
    if (!s.socket || s.socket.readyState !== WebSocket.OPEN) s.connect();
  });

  // ----- Tamaño fuente terminal -----
  btnFontInc.addEventListener('click', ()=>{
    const s = sessions.find(x=>x.id===activeId); if (!s) return;
    s.fontSize = Math.min(24, s.fontSize + 1);
    s.term.options.fontSize = s.fontSize;
    s.fit.fit();
  });
  btnFontDec.addEventListener('click', ()=>{
    const s = sessions.find(x=>x.id===activeId); if (!s) return;
    s.fontSize = Math.max(10, s.fontSize - 1);
    s.term.options.fontSize = s.fontSize;
    s.fit.fit();
  });

  // ----- Búsqueda rápida en transcript -----
  function doFind(){
    const s = sessions.find(x=>x.id===activeId); if (!s) return;
    const q = (findInput.value||'').trim();
    if (!q) return;
    const idx = s.transcript.toLowerCase().indexOf(q.toLowerCase());
    if (idx>=0) {
      // muestra los 20 caracteres alrededor en la barra de estado
      const start = Math.max(0, idx-20), end = Math.min(s.transcript.length, idx+q.length+20);
      statusEl.textContent = `🔎 “…${s.transcript.slice(start,end).replace(/\r?\n/g,' ')}…”`;
    } else {
      statusEl.textContent = '🔎 No encontrado';
    }
  }
  btnFind.addEventListener('click', doFind);
  btnFindClear.addEventListener('click', ()=>{ findInput.value=''; updateStatus(); });
  window.addEventListener('keydown', (e)=>{
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key.toLowerCase()==='f') {
      e.preventDefault(); findInput.focus(); findInput.select();
    }
  });

  // ----- Favoritos -----
  btnRunFav.addEventListener('click', ()=>{
    const cmd = favSelect.value || '';
    if (!cmd) return;
    const s = sessions.find(x=>x.id===activeId); if (!s) return;
    if (s.socket && s.socket.readyState === WebSocket.OPEN) {
      const payload = cmd + '\n';
      s.socket.send(new TextEncoder().encode(payload));
      s.term.write(cmd + '\r\n');
      s.lastCmd = cmd; renderTabs(); updateStatus();
    }
  });

  // ----- Nueva / Duplicar -----
  btnNewTab.addEventListener('click', ()=> createSession());
  btnDupTab.addEventListener('click', ()=>{
    const id = activeId;
    if (!id) return;
    createSession(id);
  });

  // ----- Atajos -----
  window.addEventListener('keydown', (e)=>{
    if (e.ctrlKey && !e.shiftKey && e.key.toLowerCase()==='s') { e.preventDefault(); btnSave.click(); }
    if (e.ctrlKey && !e.shiftKey && e.key.toLowerCase()==='l') { e.preventDefault(); btnClear.click(); }
    if (e.ctrlKey && e.shiftKey && e.key.toLowerCase()==='n') { e.preventDefault(); btnNewTab.click(); }
    if (e.ctrlKey && !e.shiftKey && e.key.toLowerCase()==='w') { e.preventDefault(); if (activeId) closeTab(activeId); }
  });

  // ----- Arranque -----
  createSession();
</script>
<?php endif; ?>
</body>
</html>
