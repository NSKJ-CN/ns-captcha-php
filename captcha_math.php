<?php
/**
 * NS科技 · 单文件算术验证码
 * 
 * 用法与滑块验证一致：
 * 1. 上传到网站任意目录
 * 2. iframe 嵌入：<iframe src="你的域名/captcha_math.php" width="300" height="200"></iframe>
 * 3. 验证通过后 postMessage 发送 token
 * 4. 后端调用 ?action=check 校验 token
 * 
 * @license MIT
 */

$CONFIG = [
    'data_dir'      => __DIR__ . '/captcha_data',
    'captcha_ttl'   => 300,
    'token_ttl'     => 300,
    'allow_origins' => '*',
];

if(!is_dir($CONFIG['data_dir'])) @mkdir($CONFIG['data_dir'], 0755, true);
$CAPTCHA_FILE = $CONFIG['data_dir'] . '/math_captcha.json';
$TOKEN_FILE   = $CONFIG['data_dir'] . '/math_tokens.json';

header('Access-Control-Allow-Origin: ' . $CONFIG['allow_origins']);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if($_SERVER['REQUEST_METHOD'] === 'OPTIONS'){ http_response_code(200); exit; }

function loadData($file){
    if(!file_exists($file)) return [];
    $fp = @fopen($file, 'r'); if(!$fp) return [];
    flock($fp, LOCK_SH); $c = stream_get_contents($fp);
    flock($fp, LOCK_UN); fclose($fp);
    $d = json_decode($c, true);
    return is_array($d) ? $d : [];
}
function saveData($file, $data){
    $fp = @fopen($file, 'c+'); if(!$fp) return false;
    flock($fp, LOCK_EX); ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return true;
}
function cleanExpired(&$data, $ttl){
    $now = time();
    foreach($data as $k => $v){ if($now - ($v['time'] ?? 0) > $ttl) unset($data[$k]); }
}
function jsonOut($arr){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit;
}
function getClientIp(){
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

$action = $_GET['action'] ?? '';

// ========== 生成题目 ==========
if($action === 'generate'){
    $type = rand(1, 3);
    switch($type){
        case 1: // 加法
            $a = rand(1, 50); $b = rand(1, 50);
            $q = "$a + $b"; $ans = $a + $b;
            break;
        case 2: // 减法
            $a = rand(20, 99); $b = rand(1, $a);
            $q = "$a - $b"; $ans = $a - $b;
            break;
        case 3: // 乘法（数字小一点）
            $a = rand(2, 9); $b = rand(2, 9);
            $q = "$a × $b"; $ans = $a * $b;
            break;
    }

    $id = bin2hex(random_bytes(16));
    $data = loadData($CAPTCHA_FILE);
    cleanExpired($data, $CONFIG['captcha_ttl']);
    $data[$id] = ['ans' => $ans, 'time' => time(), 'ip' => getClientIp()];
    saveData($CAPTCHA_FILE, $data);

    jsonOut(['code'=>0, 'id'=>$id, 'q'=>$q]);
}

// ========== 校验 ==========
if($action === 'verify'){
    $input = json_decode(file_get_contents('php://input'), true);
    $id = trim($input['id'] ?? '');
    $ans = intval($input['ans'] ?? -99999);

    if(!$id) jsonOut(['code'=>1, 'msg'=>'缺少 ID']);

    $data = loadData($CAPTCHA_FILE);
    if(!isset($data[$id])) jsonOut(['code'=>1, 'msg'=>'验证码不存在或已失效']);

    $item = $data[$id];
    if(time() - $item['time'] > $CONFIG['captcha_ttl']){
        unset($data[$id]); saveData($CAPTCHA_FILE, $data);
        jsonOut(['code'=>1, 'msg'=>'验证码已过期']);
    }

    $correct = $item['ans'];
    unset($data[$id]); saveData($CAPTCHA_FILE, $data);

    if($ans === $correct){
        $token = bin2hex(random_bytes(24));
        $tokens = loadData($TOKEN_FILE);
        cleanExpired($tokens, $CONFIG['token_ttl']);
        $tokens[$token] = ['time'=>time(), 'used'=>false, 'ip'=>getClientIp()];
        saveData($TOKEN_FILE, $tokens);
        jsonOut(['code'=>0, 'msg'=>'通过', 'token'=>$token]);
    }else{
        jsonOut(['code'=>1, 'msg'=>'答案不对，再想想']);
    }
}

// ========== 校验 token ==========
if($action === 'check'){
    $input = json_decode(file_get_contents('php://input'), true);
    $token = trim($input['token'] ?? '');
    if(!$token) jsonOut(['code'=>1, 'msg'=>'缺少 token']);

    $tokens = loadData($TOKEN_FILE);
    cleanExpired($tokens, $CONFIG['token_ttl']);
    if(!isset($tokens[$token])){
        saveData($TOKEN_FILE, $tokens);
        jsonOut(['code'=>1, 'msg'=>'token 无效或已过期']);
    }
    if(!empty($tokens[$token]['used'])) jsonOut(['code'=>1, 'msg'=>'token 已被使用']);

    $tokens[$token]['used'] = true;
    saveData($TOKEN_FILE, $tokens);
    jsonOut(['code'=>0, 'msg'=>'验证有效']);
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>算术验证</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
html,body{background:transparent;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;}
body{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:12px;}
.cap{width:260px;max-width:100%;user-select:none;}
.card{
  background:#fff;border:1px solid #eee;border-radius:12px;
  padding:18px;text-align:center;
}
.question{
  font-size:26px;font-weight:700;color:#111;
  letter-spacing:2px;margin-bottom:14px;
  font-family:ui-monospace,Menlo,Consolas,monospace;
}
.input-row{display:flex;gap:8px;}
.input-row input{
  flex:1;padding:11px 12px;font-size:15px;
  border:1px solid #e5e5e5;border-radius:10px;
  outline:none;background:#fafafa;color:#111;
  text-align:center;font-family:inherit;
  -webkit-appearance:none;
}
.input-row input:focus{border-color:#111;background:#fff;}
.input-row button{
  padding:0 18px;font-size:14px;font-weight:600;
  background:#111;color:#fff;border:none;border-radius:10px;
  cursor:pointer;font-family:inherit;
}
.input-row button:active{background:#333;}
.refresh{
  display:inline-block;margin-top:10px;font-size:12px;
  color:#6a89cc;cursor:pointer;
  border-bottom:1px solid rgba(106,137,204,.4);
}
.status{
  margin-top:10px;font-size:12px;text-align:center;
  color:#999;min-height:16px;
}
.status.ok{color:#2ecc71;}
.status.err{color:#e74c3c;}
</style>
</head>
<body>

<div class="cap">
  <div class="card">
    <div class="question" id="question">加载中...</div>
    <div class="input-row">
      <input type="number" id="ansInput" placeholder="答案" inputmode="numeric">
      <button id="submitBtn">提交</button>
    </div>
    <div class="refresh" id="refresh">换一题</div>
    <div class="status" id="status"></div>
  </div>
</div>

<script>
(function(){
  const API = location.pathname;
  const questionEl = document.getElementById('question');
  const ansInput = document.getElementById('ansInput');
  const submitBtn = document.getElementById('submitBtn');
  const refresh = document.getElementById('refresh');
  const statusEl = document.getElementById('status');

  let currentId = '';

  function setStatus(m, t){
    statusEl.textContent = m || '';
    statusEl.className = 'status' + (t ? ' ' + t : '');
  }

  async function load(){
    setStatus('加载中...');
    currentId = '';
    ansInput.value = '';
    questionEl.textContent = '...';
    try{
      const res = await fetch(API + '?action=generate&_t=' + Date.now());
      const d = await res.json();
      if(d.code === 0){
        questionEl.textContent = d.q + ' = ?';
        currentId = d.id;
        ansInput.focus();
        setStatus('');
      }else{
        setStatus('加载失败', 'err');
      }
    }catch(e){
      setStatus('网络错误', 'err');
    }
  }

  async function submit(){
    const v = ansInput.value.trim();
    if(v === '' || !currentId){ setStatus('请输入答案', 'err'); return; }
    setStatus('验证中...');
    try{
      const res = await fetch(API + '?action=verify', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: currentId, ans: parseInt(v, 10)})
      });
      const d = await res.json();
      if(d.code === 0){
        setStatus('✅ 验证通过', 'ok');
        try{
          if(window.parent && window.parent !== window){
            window.parent.postMessage({type:'captcha-success', token:d.token}, '*');
          }
        }catch(e){}
      }else{
        setStatus('❌ ' + d.msg, 'err');
        setTimeout(load, 1200);
      }
    }catch(e){
      setStatus('网络错误', 'err');
    }
  }

  submitBtn.addEventListener('click', submit);
  ansInput.addEventListener('keydown', e => { if(e.key === 'Enter') submit(); });
  refresh.addEventListener('click', load);

  load();
})();
</script>

</body>
</html>