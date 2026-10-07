<?php
/**
 * NS科技 · 单文件文字验证码
 * 用法与滑块验证一致，iframe 嵌入。
 * 
 * @license MIT
 */

$CONFIG = [
    'data_dir'      => __DIR__ . '/captcha_data',
    'captcha_ttl'   => 300,
    'token_ttl'     => 300,
    'allow_origins' => '*',
    'length'        => 4,        // 字符个数
    'img_width'     => 140,      // 图片宽度
    'img_height'    => 48,       // 图片高度
];

if(!is_dir($CONFIG['data_dir'])) @mkdir($CONFIG['data_dir'], 0755, true);
$CAPTCHA_FILE = $CONFIG['data_dir'] . '/text_captcha.json';
$TOKEN_FILE   = $CONFIG['data_dir'] . '/text_tokens.json';

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

// ========== 生成文字图 ==========
if($action === 'generate'){
    $w = $CONFIG['img_width'];
    $h = $CONFIG['img_height'];
    $len = $CONFIG['length'];

    // 字符集（去掉了容易混淆的 0/O/1/I/l）
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for($i = 0; $i < $len; $i++){
        $code .= $chars[rand(0, strlen($chars) - 1)];
    }

    $img = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($img, 245, 245, 245);
    imagefill($img, 0, 0, $bg);

    // 干扰点
    for($i = 0; $i < 80; $i++){
        $c = imagecolorallocate($img, rand(150,220), rand(150,220), rand(150,220));
        imagesetpixel($img, rand(0,$w), rand(0,$h), $c);
    }
    // 干扰线
    for($i = 0; $i < 3; $i++){
        $c = imagecolorallocate($img, rand(150,220), rand(150,220), rand(150,220));
        imageline($img, rand(0,$w), rand(0,$h), rand(0,$w), rand(0,$h), $c);
    }

    // 画字符（随机颜色 + 随机微旋转）
    $charW = $w / ($len + 1);
    for($i = 0; $i < $len; $i++){
        $color = imagecolorallocate($img, rand(20,100), rand(20,100), rand(20,100));
        $size = rand(18, 22);
        $x = $charW * $i + rand(6, 14);
        $y = rand(6, 14);
        imagestring($img, 5, $x, $y, $code[$i], $color);
    }

    ob_start(); imagepng($img); $imgData = ob_get_clean();
    imagedestroy($img);

    $id = bin2hex(random_bytes(16));
    $data = loadData($CAPTCHA_FILE);
    cleanExpired($data, $CONFIG['captcha_ttl']);
    $data[$id] = ['code' => $code, 'time' => time(), 'ip' => getClientIp()];
    saveData($CAPTCHA_FILE, $data);

    jsonOut([
        'code'=>0,
        'id'=>$id,
        'img'=>'data:image/png;base64,' . base64_encode($imgData),
        'len'=>$len
    ]);
}

// ========== 校验 ==========
if($action === 'verify'){
    $input = json_decode(file_get_contents('php://input'), true);
    $id = trim($input['id'] ?? '');
    $code = strtoupper(trim($input['code'] ?? ''));

    if(!$id) jsonOut(['code'=>1, 'msg'=>'缺少 ID']);

    $data = loadData($CAPTCHA_FILE);
    if(!isset($data[$id])) jsonOut(['code'=>1, 'msg'=>'验证码不存在或已失效']);

    $item = $data[$id];
    if(time() - $item['time'] > $CONFIG['captcha_ttl']){
        unset($data[$id]); saveData($CAPTCHA_FILE, $data);
        jsonOut(['code'=>1, 'msg'=>'验证码已过期']);
    }

    $correct = $item['code'];
    unset($data[$id]); saveData($CAPTCHA_FILE, $data);

    if($code === $correct){
        $token = bin2hex(random_bytes(24));
        $tokens = loadData($TOKEN_FILE);
        cleanExpired($tokens, $CONFIG['token_ttl']);
        $tokens[$token] = ['time'=>time(), 'used'=>false, 'ip'=>getClientIp()];
        saveData($TOKEN_FILE, $tokens);
        jsonOut(['code'=>0, 'msg'=>'通过', 'token'=>$token]);
    }else{
        jsonOut(['code'=>1, 'msg'=>'字符不正确，大小写不限']);
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
<title>文字验证</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
html,body{background:transparent;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;}
body{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:12px;}
.cap{width:260px;max-width:100%;user-select:none;}
.card{
  background:#fff;border:1px solid #eee;border-radius:12px;
  padding:16px;text-align:center;
}
.img-row{display:flex;gap:8px;align-items:center;margin-bottom:12px;}
.img-row img{
  flex:1;height:48px;border-radius:8px;
  background:#f5f5f5;display:block;object-fit:cover;
}
.img-row .refresh{
  width:48px;height:48px;flex-shrink:0;
  display:grid;place-items:center;
  background:#f5f5f5;border:none;border-radius:8px;
  font-size:18px;color:#666;cursor:pointer;
}
.img-row .refresh:active{background:#e8e8e8;}
.input-row{display:flex;gap:8px;}
.input-row input{
  flex:1;padding:11px 12px;font-size:15px;
  border:1px solid #e5e5e5;border-radius:10px;
  outline:none;background:#fafafa;color:#111;
  text-align:center;font-family:inherit;letter-spacing:2px;
  text-transform:uppercase;
  -webkit-appearance:none;
}
.input-row input:focus{border-color:#111;background:#fff;}
.input-row button{
  padding:0 18px;font-size:14px;font-weight:600;
  background:#111;color:#fff;border:none;border-radius:10px;
  cursor:pointer;font-family:inherit;
}
.input-row button:active{background:#333;}
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
    <div class="img-row">
      <img id="capImg" alt="">
      <button class="refresh" id="refresh" type="button">↻</button>
    </div>
    <div class="input-row">
      <input type="text" id="codeInput" placeholder="输入图中字符" maxlength="8" autocomplete="off" autocorrect="off" autocapitalize="characters" spellcheck="false">
      <button id="submitBtn" type="button">提交</button>
    </div>
    <div class="status" id="status"></div>
  </div>
</div>

<script>
(function(){
  const API = location.pathname;
  const capImg = document.getElementById('capImg');
  const codeInput = document.getElementById('codeInput');
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
    codeInput.value = '';
    capImg.src = '';
    try{
      const res = await fetch(API + '?action=generate&_t=' + Date.now());
      const d = await res.json();
      if(d.code === 0){
        capImg.src = d.img;
        currentId = d.id;
        codeInput.maxLength = d.len;
        codeInput.focus();
        setStatus('');
      }else{
        setStatus('加载失败', 'err');
      }
    }catch(e){
      setStatus('网络错误', 'err');
    }
  }

  async function submit(){
    const v = codeInput.value.trim();
    if(v === '' || !currentId){ setStatus('请输入字符', 'err'); return; }
    setStatus('验证中...');
    try{
      const res = await fetch(API + '?action=verify', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: currentId, code: v})
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
  codeInput.addEventListener('keydown', e => { if(e.key === 'Enter') submit(); });
  refresh.addEventListener('click', load);

  load();
})();
</script>

</body>
</html>