<?php
/**
 * NS科技 · 单文件滑块验证码
 * 
 * 使用说明：
 * 1. 把本文件放到网站任意目录，比如 /captcha.php
 * 2. 其他网站可以通过 iframe 嵌入：<iframe src="你的域名/captcha.php" width="300" height="230"></iframe>
 * 3. 验证通过后，iframe 会向父页面发送 postMessage，包含 token
 * 4. 父页面拿到 token 后，可以提交给自己后端
 * 5. 后端调用 captcha.php?action=check 校验 token 是否有效
 * 
 * 依赖：PHP 7.4+，需开启 GD 扩展
 * 存储：data 目录下的 json 文件（自动创建）
 * 
 * @author NS科技
 * @license MIT
 */

// ==================== 配置 ====================
$CONFIG = [
    'data_dir'        => __DIR__ . '/captcha_data',   // 数据目录
    'captcha_ttl'     => 300,    // 验证码有效期（秒）
    'token_ttl'       => 300,    // token 有效期（秒）
    'tolerance'       => 10,     // 拖动容差（像素）
    'img_width'       => 280,    // 图片宽度
    'img_height'      => 150,    // 图片高度
    'gap_size'        => 40,     // 拼图块大小
    'allow_origins'   => '*',    // 允许的跨域来源，'*' 表示全部
];

// ==================== 初始化 ====================
if(!is_dir($CONFIG['data_dir'])){
    @mkdir($CONFIG['data_dir'], 0755, true);
}
$CAPTCHA_FILE = $CONFIG['data_dir'] . '/captcha.json';
$TOKEN_FILE   = $CONFIG['data_dir'] . '/tokens.json';

// CORS
header('Access-Control-Allow-Origin: ' . $CONFIG['allow_origins']);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if($_SERVER['REQUEST_METHOD'] === 'OPTIONS'){ http_response_code(200); exit; }

// ==================== 工具函数 ====================
function loadData($file){
    if(!file_exists($file)) return [];
    $fp = @fopen($file, 'r'); if(!$fp) return [];
    flock($fp, LOCK_SH);
    $c = stream_get_contents($fp);
    flock($fp, LOCK_UN); fclose($fp);
    $d = json_decode($c, true);
    return is_array($d) ? $d : [];
}
function saveData($file, $data){
    $fp = @fopen($file, 'c+'); if(!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return true;
}
function cleanExpired(&$data, $ttl){
    $now = time();
    foreach($data as $k => $v){
        if($now - ($v['time'] ?? 0) > $ttl) unset($data[$k]);
    }
}
function jsonOut($arr){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
function getClientIp(){
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// ==================== 接口：生成验证码 ====================
$action = $_GET['action'] ?? '';

if($action === 'generate'){
    $w = $CONFIG['img_width'];
    $h = $CONFIG['img_height'];
    $gapSize = $CONFIG['gap_size'];

    $img = imagecreatetruecolor($w, $h);

    // 随机背景
    $r = rand(200, 255); $g = rand(200, 255); $b = rand(200, 255);
    imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));

    // 干扰线
    for($i = 0; $i < 15; $i++){
        $c = imagecolorallocate($img, rand(100,200), rand(100,200), rand(100,200));
        imageline($img, rand(0,$w), rand(0,$h), rand(0,$w), rand(0,$h), $c);
    }
    // 干扰点
    for($i = 0; $i < 200; $i++){
        $c = imagecolorallocate($img, rand(100,220), rand(100,220), rand(100,220));
        imagesetpixel($img, rand(0,$w), rand(0,$h), $c);
    }

    // 缺口位置
    $gapX = rand(90, $w - $gapSize - 10);
    $gapY = rand(20, $h - $gapSize - 20);

    // 裁剪拼图块
    $piece = imagecreatetruecolor($gapSize, $gapSize);
    imagecopy($piece, $img, 0, 0, $gapX, $gapY, $gapSize, $gapSize);
    $border = imagecolorallocate($piece, 255, 255, 255);
    imagerectangle($piece, 0, 0, $gapSize-1, $gapSize-1, $border);

    // 挖缺口（半透明黑）
    $hole = imagecolorallocatealpha($img, 0, 0, 0, 70);
    imagefilledrectangle($img, $gapX, $gapY, $gapX+$gapSize, $gapY+$gapSize, $hole);

    // 导出
    ob_start(); imagepng($img); $bgData = ob_get_clean();
    ob_start(); imagepng($piece); $pieceData = ob_get_clean();
    imagedestroy($img); imagedestroy($piece);

    // 存答案
    $id = bin2hex(random_bytes(16));
    $data = loadData($CAPTCHA_FILE);
    cleanExpired($data, $CONFIG['captcha_ttl']);
    $data[$id] = [
        'gapX' => $gapX,
        'time' => time(),
        'ip'   => getClientIp()
    ];
    saveData($CAPTCHA_FILE, $data);

    jsonOut([
        'code'    => 0,
        'id'      => $id,
        'bg'      => 'data:image/png;base64,' . base64_encode($bgData),
        'piece'   => 'data:image/png;base64,' . base64_encode($pieceData),
        'w'       => $w,
        'h'       => $h,
        'gapY'    => $gapY,
        'gapSize' => $gapSize
    ]);
}

// ==================== 接口：校验拖动 ====================
if($action === 'verify'){
    $input = json_decode(file_get_contents('php://input'), true);
    $id = trim($input['id'] ?? '');
    $sliderX = intval($input['x'] ?? -1);

    if(!$id) jsonOut(['code'=>1, 'msg'=>'缺少验证 ID']);

    $data = loadData($CAPTCHA_FILE);
    if(!isset($data[$id])){
        jsonOut(['code'=>1, 'msg'=>'验证码不存在或已失效']);
    }

    $item = $data[$id];
    if(time() - $item['time'] > $CONFIG['captcha_ttl']){
        unset($data[$id]); saveData($CAPTCHA_FILE, $data);
        jsonOut(['code'=>1, 'msg'=>'验证码已过期']);
    }

    $gapX = $item['gapX'];
    unset($data[$id]); saveData($CAPTCHA_FILE, $data);

    if(abs($sliderX - $gapX) <= $CONFIG['tolerance']){
        $token = bin2hex(random_bytes(24));
        $tokens = loadData($TOKEN_FILE);
        cleanExpired($tokens, $CONFIG['token_ttl']);
        $tokens[$token] = [
            'time' => time(),
            'used' => false,
            'ip'   => getClientIp()
        ];
        saveData($TOKEN_FILE, $tokens);

        jsonOut(['code'=>0, 'msg'=>'通过', 'token'=>$token]);
    }else{
        $diff = abs($sliderX - $gapX);
        jsonOut(['code'=>1, 'msg'=>'位置不正确，差 ' . $diff . ' 像素']);
    }
}

// ==================== 接口：校验 token（供别人后端调用） ====================
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
    if(!empty($tokens[$token]['used'])){
        jsonOut(['code'=>1, 'msg'=>'token 已被使用']);
    }

    // 用完即废
    $tokens[$token]['used'] = true;
    saveData($TOKEN_FILE, $tokens);
    jsonOut(['code'=>0, 'msg'=>'验证有效']);
}

// ==================== 页面（嵌入用） ====================
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>滑块验证</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
html,body{background:transparent;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;}
body{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:10px;}
.cap{width:280px;max-width:100%;user-select:none;}
.cap-img-wrap{
  position:relative;width:100%;aspect-ratio:280/150;
  border-radius:10px;overflow:hidden;
  border:1px solid #eee;background:#f5f5f5;
}
.cap-img-wrap #bg{width:100%;height:100%;display:block;}
.cap-img-wrap #piece{
  position:absolute;top:0;left:0;width:40px;height:40px;
  display:none;pointer-events:none;
  filter:drop-shadow(0 0 4px rgba(0,0,0,.4));
}
.slider{
  position:relative;width:100%;height:42px;margin-top:10px;
  background:#f0f0f0;border-radius:10px;overflow:hidden;
}
.slider-fill{
  position:absolute;top:0;left:0;height:100%;width:0;
  background:linear-gradient(90deg,#6a89cc,#9db4e8);
  border-radius:10px;transition:width .05s;
}
.slider-btn{
  position:absolute;top:0;left:0;width:42px;height:42px;
  background:#fff;border-radius:10px;
  box-shadow:0 2px 6px rgba(0,0,0,.15);
  display:flex;align-items:center;justify-content:center;
  font-size:16px;color:#6a89cc;cursor:grab;
  transition:left .05s;
}
.slider-text{
  position:absolute;top:0;left:0;width:100%;height:100%;
  display:flex;align-items:center;justify-content:center;
  font-size:12px;color:#999;pointer-events:none;
}
.status{
  margin-top:8px;font-size:12px;text-align:center;
  color:#999;min-height:16px;
}
.status.ok{color:#2ecc71;}
.status.err{color:#e74c3c;}
</style>
</head>
<body>

<div class="cap">
  <div class="cap-img-wrap">
    <img id="bg" alt="">
    <img id="piece" alt="">
  </div>
  <div class="slider">
    <div class="slider-fill" id="fill"></div>
    <div class="slider-text" id="stext">按住滑块向右拖动</div>
    <div class="slider-btn" id="btn">➜</div>
  </div>
  <div class="status" id="status"></div>
</div>

<script>
(function(){
  const API = location.pathname;   // 就是本文件
  const bg = document.getElementById('bg');
  const piece = document.getElementById('piece');
  const btn = document.getElementById('btn');
  const fill = document.getElementById('fill');
  const stext = document.getElementById('stext');
  const statusEl = document.getElementById('status');

  let currentId = '';
  let maxLeft = 0;
  let dragging = false;
  let startX = 0;
  let curLeft = 0;
  let scale = 1;
  let imgW = 280;

  function setStatus(m, t){
    statusEl.textContent = m || '';
    statusEl.className = 'status' + (t ? ' ' + t : '');
  }

  async function load(){
    setStatus('加载中...');
    currentId = ''; curLeft = 0;
    piece.style.display = 'none';
    btn.style.left = '0px';
    fill.style.width = '0px';
    stext.style.opacity = '1';

    try{
      const res = await fetch(API + '?action=generate&_t=' + Date.now());
      const d = await res.json();
      if(d.code === 0){
        bg.src = d.bg;
        piece.src = d.piece;
        imgW = d.w;
        piece.style.width = d.gapSize + 'px';
        piece.style.height = d.gapSize + 'px';
        piece.style.top = d.gapY + 'px';
        piece.style.display = 'block';
        currentId = d.id;
        // 等图片渲染后计算缩放
        setTimeout(() => {
          const renderW = bg.clientWidth;
          scale = renderW / imgW;
          maxLeft = renderW - 42;
        }, 80);
        setStatus('');
      }else{
        setStatus('加载失败', 'err');
      }
    }catch(e){
      setStatus('网络错误', 'err');
    }
  }

  function onStart(e){
    if(curLeft > 0 || !currentId) return;
    dragging = true;
    startX = e.touches ? e.touches[0].clientX : e.clientX;
    stext.style.opacity = '0';
  }
  function onMove(e){
    if(!dragging) return;
    e.preventDefault();
    const x = e.touches ? e.touches[0].clientX : e.clientX;
    let diff = x - startX;
    if(diff < 0) diff = 0;
    if(diff > maxLeft) diff = maxLeft;
    curLeft = diff;
    btn.style.left = diff + 'px';
    fill.style.width = (diff + 21) + 'px';
    piece.style.left = diff + 'px';
  }
  function onEnd(){
    if(!dragging) return;
    dragging = false;
    if(curLeft < 5){
      btn.style.left = '0px';
      fill.style.width = '0px';
      stext.style.opacity = '1';
      piece.style.left = '0px';
      return;
    }
    verify(curLeft);
  }

  async function verify(x){
    setStatus('验证中...');
    const origX = Math.round(x / scale);
    try{
      const res = await fetch(API + '?action=verify', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: currentId, x: origX})
      });
      const d = await res.json();
      if(d.code === 0){
        setStatus('✅ 验证通过', 'ok');
        // 通知父页面
        try{
          if(window.parent && window.parent !== window){
            window.parent.postMessage({
              type: 'captcha-success',
              token: d.token
            }, '*');
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

  btn.addEventListener('mousedown', onStart);
  btn.addEventListener('touchstart', onStart, {passive:true});
  document.addEventListener('mousemove', onMove);
  document.addEventListener('touchmove', onMove, {passive:false});
  document.addEventListener('mouseup', onEnd);
  document.addEventListener('touchend', onEnd);

  load();
})();
</script>

</body>
</html>