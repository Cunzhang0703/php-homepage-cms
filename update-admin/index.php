<?php
/**
 * 云更新管理页（液态玻璃风格，新页面，非现有后台改造）
 * 复用站点 includes/common.php 的 DB / 鉴权。需登录管理员。
 */
require_once __DIR__ . '/../includes/common.php';
require_once __DIR__ . '/lib_update.php';

if (!$islogin) { header('Location: ../admin/login.php'); exit; }
ensureUpdateSchema();

// 操作反馈
$msg = ''; $msgType = '';
if (isset($_GET['ok']))  { $msg = '✅ ' . htmlspecialchars($_GET['ok']);  $msgType = 'success'; }
if (isset($_GET['err'])) { $msg = '❌ ' . htmlspecialchars($_GET['err']); $msgType = 'danger'; }

$current    = currentPublished();
$versions   = listVersions();
$updateUrl  = conf('update_url', '');
$jsonUrl    = webBase() . '/update.json';
$channelTag = array('release' => 'rel', 'beta' => 'beta', 'dev' => 'gray');
$channelTxt = array('release' => 'release', 'beta' => 'beta', 'dev' => 'dev');
$statusTag  = array('current' => array('cur', '当前发布'), 'pending' => array('warn', '待确认'), 'superseded' => array('sup', '已被取代'), 'archived' => array('sup', '已归档'));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>云更新管理</title>
<style>
  :root{
    --bg1:#eef2ff; --bg2:#f5f3ff; --bg3:#ecfeff;
    --text:#1e293b; --muted:#64748b; --accent:#6366f1; --accent2:#06b6d4;
    --glass:rgba(255,255,255,.55); --glass-brd:rgba(255,255,255,.7);
    --shadow:0 10px 40px rgba(99,102,241,.18); --danger:#ef4444; --ok:#10b981;
  }
  [data-theme="dark"]{
    --bg1:#0f172a; --bg2:#1e1b4b; --bg3:#083344;
    --text:#e2e8f0; --muted:#94a3b8; --accent:#818cf8; --accent2:#22d3ee;
    --glass:rgba(30,41,59,.55); --glass-brd:rgba(148,163,184,.25);
    --shadow:0 10px 40px rgba(0,0,0,.45);
  }
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,"Segoe UI",system-ui,"PingFang SC","Microsoft YaHei",sans-serif;
    color:var(--text);min-height:100vh;overflow-x:hidden;padding:32px 16px;
    background:linear-gradient(135deg,var(--bg1),var(--bg2) 45%,var(--bg3));
    transition:background .4s ease,color .4s ease}
  .blob{position:fixed;border-radius:50%;filter:blur(70px);opacity:.5;z-index:0;animation:float 14s ease-in-out infinite}
  .blob.a{width:340px;height:340px;background:#818cf8;top:-80px;left:-60px}
  .blob.b{width:300px;height:300px;background:#22d3ee;bottom:-90px;right:-40px;animation-delay:-7s}
  @keyframes float{0%,100%{transform:translateY(0) translateX(0)}50%{transform:translateY(30px) translateX(20px)}}
  .wrap{position:relative;z-index:1;max-width:1040px;margin:0 auto}
  .top{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;flex-wrap:wrap;gap:12px}
  .top h1{font-size:24px;font-weight:700}
  .top .sub{color:var(--muted);font-size:13px;margin-top:4px}
  .glass{background:var(--glass);backdrop-filter:blur(22px) saturate(180%);-webkit-backdrop-filter:blur(22px) saturate(180%);
    border:1px solid var(--glass-brd);border-radius:20px;box-shadow:var(--shadow);padding:22px}
  .toggle{cursor:pointer;border:1px solid var(--glass-brd);background:var(--glass);border-radius:999px;
    padding:8px 14px;font-size:13px;color:var(--text);backdrop-filter:blur(12px);transition:.25s}
  .toggle:hover{transform:translateY(-2px)}
  .grid{display:grid;grid-template-columns:1.05fr .95fr;gap:18px;margin-top:18px}
  @media(max-width:820px){.grid{grid-template-columns:1fr}}
  .panel-title{font-size:15px;font-weight:600;margin-bottom:14px;display:flex;align-items:center;gap:8px}
  label{display:block;font-size:12px;color:var(--muted);margin:12px 0 6px}
  input[type=text],textarea,select{width:100%;padding:11px 13px;border-radius:12px;border:1px solid var(--glass-brd);
    background:rgba(255,255,255,.4);color:var(--text);font-size:14px;outline:none;transition:.2s;font-family:inherit}
  [data-theme="dark"] input,[data-theme="dark"] textarea,[data-theme="dark"] select{background:rgba(15,23,42,.5)}
  input:focus,textarea:focus,select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(99,102,241,.2)}
  textarea{resize:vertical;min-height:74px}
  .row{display:flex;gap:12px}.row>div{flex:1}
  .btn{cursor:pointer;border:none;border-radius:12px;padding:11px 18px;font-size:14px;font-weight:600;color:#fff;
    background:linear-gradient(120deg,var(--accent),var(--accent2));transition:.25s;box-shadow:0 6px 18px rgba(99,102,241,.3)}
  .btn:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(99,102,241,.4)}
  .btn.ghost{background:transparent;color:var(--text);border:1px solid var(--glass-brd);box-shadow:none}
  .btn-row{display:flex;gap:10px;margin-top:18px;flex-wrap:wrap}
  .ver{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-radius:14px;
    background:rgba(255,255,255,.35);border:1px solid var(--glass-brd);margin-bottom:10px;transition:.2s}
  [data-theme="dark"] .ver{background:rgba(15,23,42,.4)}
  .ver:hover{transform:translateX(3px)}
  .ver .v{font-weight:700;font-size:15px}
  .ver .meta{font-size:12px;color:var(--muted);margin-top:2px;word-break:break-all}
  .tag{font-size:11px;padding:2px 8px;border-radius:999px;font-weight:600}
  .tag.rel{background:rgba(16,185,129,.18);color:var(--ok)}
  .tag.beta{background:rgba(245,158,11,.18);color:#f59e0b}
  .tag.gray{background:rgba(99,102,241,.18);color:var(--accent)}
  .tag.cur{background:linear-gradient(120deg,var(--accent),var(--accent2));color:#fff}
  .tag.warn{background:rgba(245,158,11,.18);color:#f59e0b}
  .tag.sup{background:rgba(100,116,139,.15);color:#64748b}
  .ver .acts{display:flex;gap:8px;flex-shrink:0;margin-left:10px}
  .mini{cursor:pointer;font-size:12px;padding:5px 10px;border-radius:9px;border:1px solid var(--glass-brd);
    background:var(--glass);color:var(--text);transition:.2s}
  .mini:hover{transform:translateY(-1px)}
  .mini.danger{color:var(--danger);border-color:rgba(239,68,68,.4)}
  .note{font-size:12px;color:var(--muted);margin-top:10px;line-height:1.7}
  .alert{padding:11px 14px;border-radius:12px;font-size:13px;margin-bottom:16px}
  .alert.success{background:rgba(16,185,129,.15);color:var(--ok);border:1px solid rgba(16,185,129,.3)}
  .alert.danger{background:rgba(239,68,68,.12);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
  .field-error{min-height:18px;margin-top:6px;color:var(--danger);font-size:12px;line-height:1.5}
  input.invalid{border-color:var(--danger)!important;box-shadow:0 0 0 3px rgba(239,68,68,.16)!important}
  code{font-size:11px;background:rgba(99,102,241,.12);padding:1px 5px;border-radius:5px}
</style>
</head>
<body>
  <div class="blob a"></div><div class="blob b"></div>
  <div class="wrap">
    <div class="top">
      <div>
        <h1>☁️ 云更新管理</h1>
        <div class="sub">上传版本包 · 一键发布 · 历史回退（新页面，复用站点登录态）</div>
      </div>
      <button class="toggle" id="themeBtn">🌙 暗色</button>
    </div>

    <?php if ($msg): ?><div class="alert <?php echo $msgType; ?>"><?php echo $msg; ?></div><?php endif; ?>

    <div class="grid">
      <!-- 左：发布 -->
      <div class="glass">
        <div class="panel-title">🚀 发布新版本</div>
        <form id="publishForm" method="post" action="upload.php" enctype="multipart/form-data" novalidate>
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="publish">
          <label>当前线上版本</label>
          <input type="text" value="<?php echo $current ? 'v' . htmlspecialchars($current['version']) : '（暂无发布）'; ?>" disabled>
          <label for="versionInput">新版本号</label>
          <input id="versionInput" type="text" name="version" placeholder="例如 1.20" inputmode="decimal" autocomplete="off" pattern="[0-9]\.[0-9]{2}" aria-describedby="versionError" required>
          <div id="versionError" class="field-error" role="alert" aria-live="polite"></div>
          <div class="note">格式固定为 <code>x.xx</code>：主版本号 1 位、次版本号 2 位，例如 <code>1.00</code>、<code>1.20</code>、<code>2.35</code>。不支持 <code>1.2</code>、<code>1.121</code> 或 <code>10.20</code>。</div>
          <div class="row">
            <div>
              <label>更新通道</label>
              <select name="channel">
                <option value="release">release（生产）</option>
                <option value="beta">beta（测试）</option>
                <option value="dev">dev（内测）</option>
              </select>
            </div>
            <div>
              <label>最低 PHP</label>
              <input type="text" name="min_php" placeholder="7.2" value="7.2">
            </div>
          </div>
          <label>版本包（.zip，由部署包脚本生成）</label>
          <input type="file" id="pkgInput" name="pkg" accept=".zip" required>
          <label>更新说明</label>
          <textarea id="notes" name="notes" placeholder="选择压缩包后将自动提取包内「更新记录.txt」填充，可手动编辑…"></textarea>
          <div class="note" id="notesHint">可直接上传普通源码 ZIP。系统会自动剔除 <code>includes/db.php</code>、<code>data/</code>、<code>uploads/</code>、<code>install/</code> 与环境配置文件，再生成安全升级包；并会读取包内 <code>更新记录.txt</code> 作为说明。上传后仅<b>暂存为待更新包</b>，需到后台「云更新」中手动确认。</div>
          <div class="btn-row">
            <button type="submit" class="btn">📦 上传并暂存为待更新包</button>
          </div>
        </form>
      </div>

      <!-- 右：版本库 + 更新源 -->
      <div class="glass">
        <div class="panel-title">📚 版本库（最近）</div>
        <?php if (!$versions): ?>
          <div class="note">暂无发布记录。首次上传并暂存一个版本包后，系统会自动生成 <code>update.json</code>；在此之前，客户端检查更新会显示首次使用提示。</div>
        <?php else: foreach ($versions as $it):
          $cls = $channelTag[$it['channel']] ?? 'gray';
          $txt = $channelTxt[$it['channel']] ?? $it['channel'];
          $isCur = (int)$it['is_current'] === 1;
          $st = $statusTag[$it['status'] ?? 'current'] ?? $statusTag['current'];
        ?>
          <div class="ver">
            <div>
              <div class="v">v<?php echo htmlspecialchars($it['version']); ?>
                <span class="tag <?php echo $cls; ?>"><?php echo $txt; ?></span>
                <?php if ($isCur): ?><span class="tag cur">当前发布</span><?php endif; ?>
                <?php if (!$isCur && ($it['status'] ?? '') === 'pending'): ?><span class="tag warn">待确认</span><?php endif; ?>
              </div>
              <div class="meta"><?php echo htmlspecialchars(substr((string)($it['created_at']??''),0,10)); ?> · <?php echo htmlspecialchars($it['file']); ?></div>
            </div>
            <div class="acts">
              <?php if ($isCur): ?><span class="tag cur">线上</span>
              <?php elseif (($it['status'] ?? '') === 'pending'): ?><span class="tag warn">待后台确认</span>
              <?php else: ?>
                <form method="post" action="upload.php" onsubmit="return confirm('确认回退到 v<?php echo htmlspecialchars($it['version']); ?>？');">
                  <?php echo csrfField(); ?>
                  <input type="hidden" name="action" value="rollback">
                  <input type="hidden" name="id" value="<?php echo (int)$it['id']; ?>">
                  <button class="mini" type="submit">回退到此</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; endif; ?>

        <div class="panel-title" style="margin-top:18px;">🔗 更新源地址（客户端读取）</div>
        <form method="post" action="upload.php">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="savesrc">
          <input type="text" name="update_url" value="<?php echo htmlspecialchars($jsonUrl); ?>" placeholder="https://your-domain.com/update-admin/update.json">
          <div class="note">客户端 <code>admin/update.php</code> 将从此地址拉取 update.json。已自动填入本管理端的 update.json 地址，保存即可生效。</div>
          <div class="btn-row">
            <button type="submit" class="btn ghost">💾 保存为更新源</button>
          </div>
        </form>
      </div>
    </div>
  </div>

<script>
  const root=document.documentElement, btn=document.getElementById('themeBtn');
  const saved=localStorage.getItem('lg-theme'); if(saved){root.setAttribute('data-theme',saved);syncBtn();}
  btn.onclick=()=>{const cur=root.getAttribute('data-theme')==='dark'?'light':'dark';if(cur==='light')root.removeAttribute('data-theme');else root.setAttribute('data-theme','dark');localStorage.setItem('lg-theme',cur);syncBtn();};
  function syncBtn(){btn.textContent=root.getAttribute('data-theme')==='dark'?'☀️ 亮色':'🌙 暗色';}

  // 新版本号只允许 x.xx。前端即时提示提升体验，后端 upload.php 会再次校验，避免绕过。
  const publishForm=document.getElementById('publishForm');
  const versionInput=document.getElementById('versionInput');
  const versionError=document.getElementById('versionError');
  const versionPattern=/^[0-9]\.[0-9]{2}$/;
  function validateVersionInput(showError){
    if(!versionInput) return true;
    const value=versionInput.value.trim();
    let message='';
    if(!value){ message='请输入新版本号，例如 1.20。'; }
    else if(!versionPattern.test(value)){ message='版本号格式不正确：请使用 x.xx，例如 1.00、1.20 或 2.35；不允许 1.2、1.121 或 10.20。'; }
    versionInput.setCustomValidity(message);
    versionInput.classList.toggle('invalid',!!message);
    versionError.textContent=showError||message ? message : '';
    return !message;
  }
  if(versionInput){
    versionInput.addEventListener('input',()=>validateVersionInput(false));
    versionInput.addEventListener('blur',()=>validateVersionInput(true));
  }
  if(publishForm){
    publishForm.addEventListener('submit',(event)=>{
      if(!validateVersionInput(true)){
        event.preventDefault();
        versionInput.focus();
      }
    });
  }

  // 上传压缩包后，自动提取包内「更新记录.txt」填充更新说明，并允许二次编辑
  const pkgInput=document.getElementById('pkgInput');
  const notesEl=document.getElementById('notes');
  const notesHint=document.getElementById('notesHint');
  if(pkgInput&&notesEl){
    pkgInput.addEventListener('change',async()=>{
      const f=pkgInput.files[0]; if(!f)return;
      notesHint.innerHTML='⏳ 正在从压缩包读取 <code>更新记录.txt</code>…';
      try{
        const fd=new FormData(); fd.append('pkg',f);
        const csrf=document.querySelector('input[name="csrf_token"]'); if(csrf)fd.append('csrf_token',csrf.value);
        const r=await fetch('upload.php?action=peek',{method:'POST',body:fd});
        const j=await r.json();
        if(j.ok&&j.notes){notesEl.value=j.notes;notesHint.innerHTML='✅ 已从压缩包 <code>更新记录.txt</code> 自动提取，可继续补充或修改后发布。';}
        else{notesHint.innerHTML='ℹ️ 未在压缩包内找到 <code>更新记录.txt</code>，请手动填写更新说明。';}
      }catch(e){notesHint.textContent='⚠️ 自动提取失败，请手动填写更新说明。';}
    });
  }
</script>
</body>
</html>
