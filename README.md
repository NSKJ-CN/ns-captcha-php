# NS验证 · NS Captcha

> 一个纯 PHP 单文件验证码系统，零依赖、开箱即用、MIT 开源。
> 支持三种验证方式：**滑块拼图**、**算术题**、**图形字符**。

---

## ✨ 特性

- 📦 **单文件部署** —— 每个验证方式就是一个 PHP 文件，扔哪都能跑
- 🗄️ **零依赖** —— 不需要数据库，数据存本地 JSON 文件
- 🎨 **无第三方库** —— 纯原生 PHP + GD，不依赖 Composer
- 🔒 **防重放攻击** —— token 一次有效，用完即废
- ⏱️ **自动过期** —— 验证码 5 分钟过期，token 5 分钟过期
- 📱 **移动端适配** —— 自适应屏幕宽度，手机上也能用
- 🌐 **跨域支持** —— 默认允许所有来源 iframe 嵌入（可配置）
- 🎯 **统一接口** —— 三种验证方式调用方式完全一致

---

## 📂 文件清单

| 文件 | 验证方式 | 用户操作 |
|------|---------|---------|
| `captcha.php` | 滑块拼图 | 拖动滑块对齐缺口 |
| `captcha_math.php` | 算术题 | 输入算式答案 |
| `captcha_text.php` | 图形字符 | 输入图片中的字符 |

> 三个文件共用一个 `captcha_data/` 目录（首次运行自动创建）。

---

## 🚀 快速开始

### 1. 部署

把需要的 PHP 文件上传到你的网站任意目录，例如：

```
/your-site/
├── captcha.php
└── captcha_data/     ← 首次访问自动创建
```

**环境要求：**
- PHP 7.4+（推荐 8.0+）
- 开启 GD 扩展
- 目录可写权限

### 2. 嵌入到你的网站

```html
<iframe 
  src="https://你的域名/captcha.php" 
  width="300" 
  height="230" 
  frameborder="0">
</iframe>

<script>
window.addEventListener('message', function(e){
  if(e.data && e.data.type === 'captcha-success'){
    const token = e.data.token;
    console.log('验证通过，token:', token);
    fetch('/你的登录接口', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({token: token})
    });
  }
});
</script>
```

### 3. 后端校验 token

**PHP 示例：**

```php
$token = $_POST['token'];

$res = file_get_contents('https://你的域名/captcha.php?action=check', false, stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/json',
        'content' => json_encode(['token' => $token])
    ]
]));

$result = json_decode($res, true);

if($result['code'] === 0){
    // ✅ 验证通过
}else{
    // ❌ 验证失败
}
```

**Python 示例：**

```python
import requests

res = requests.post(
    'https://你的域名/captcha.php?action=check',
    json={'token': token}
)
result = res.json()

if result['code'] == 0:
    # ✅ 验证通过
    pass
```

**Node.js 示例：**

```javascript
const res = await fetch('https://你的域名/captcha.php?action=check', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({token})
});
const result = await res.json();

if(result.code === 0){
  // ✅ 验证通过
}
```

---

## 📖 API 接口

所有验证方式共用同一套接口，把 URL 里的 `captcha.php` 换成对应的文件名即可。

### 生成验证码

```
GET  captcha.php?action=generate
```

**响应：**

```json
{
  "code": 0,
  "id": "a1b2c3d4e5f6...",
  "bg": "data:image/png;base64,...",
  "piece": "data:image/png;base64,...",
  "w": 280,
  "h": 150,
  "gapY": 32,
  "gapSize": 40
}
```

### 校验拖动

```
POST  captcha.php?action=verify
Body: {"id": "xxx", "x": 120}
```

**成功响应：**

```json
{
  "code": 0,
  "msg": "通过",
  "token": "abcdef123456..."
}
```

**失败响应：**

```json
{
  "code": 1,
  "msg": "位置不正确，差 5 像素"
}
```

### 校验 token

```
POST  captcha.php?action=check
Body: {"token": "abcdef123456..."}
```

**响应：**

```json
{"code": 0, "msg": "验证有效"}
```
或
```json
{"code": 1, "msg": "token 无效或已过期"}
```

---

## 🔐 安全建议

### 1. 保护数据目录

把 `captcha_data/` 目录加上访问限制，避免被人直接下载答案。

**Nginx：**

```nginx
location ^~ /captcha_data/ {
    deny all;
    return 403;
}
```

**Apache（`.htaccess`）：**

```apache
Order deny,allow
Deny from all
```

### 2. 限制跨域来源

`captcha.php` 顶部的 `$CONFIG` 里可以指定：

```php
'allow_origins' => 'https://your-site.com',
```

### 3. 生产环境建议

- 用 HTTPS 访问
- 定期清理 `captcha_data/` 里的过期数据（脚本会自动清理）
- 如果流量大，建议把 JSON 换成 Redis / 数据库

---

## ⚙️ 配置项

每个文件顶部都有一段 `$CONFIG` 数组，可以自定义：

```php
$CONFIG = [
    'data_dir'      => __DIR__ . '/captcha_data',
    'captcha_ttl'   => 300,
    'token_ttl'     => 300,
    'tolerance'     => 10,
    'img_width'     => 280,
    'img_height'    => 150,
    'gap_size'      => 40,
    'allow_origins' => '*',
];
```

---

## 📜 License

MIT License

```
Copyright (c) 2026 NS科技

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

上述版权声明及本许可声明应包含在所有内容中
软件的副本或大部分内容。

软件以“现状”提供，不提供任何形式的保证，不提供任何明确或
默示，包括但不限于可商业性保证，
适合特定目的且不侵权。无论如何，都不会
作者或版权持有人对任何索赔、损害或其他责任承担责任
责任，无论是合同诉讼、侵权行为还是其他行为，均源自以下情况，
无论是在软件之外还是与使用或其他交易相关的
软件。
```

---

## 📮 联系方式

- 作者：NS科技
- 邮箱：hlcsnbb@foxmail.com

---

**⭐ 如果这个项目帮到了你，请点个 Star 支持一下！**
