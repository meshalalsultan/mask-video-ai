<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$ready = trim($config['api_key']) !== '';
$lastId = $_SESSION['tasks'] === [] ? '' : end($_SESSION['tasks']);
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
<title>ماسك — استوديو الفيديو</title>
<link rel="stylesheet" href="assets/app.css"><script defer src="assets/app.js"></script>
</head>
<body data-ready="<?= $ready ? '1' : '0' ?>" data-last-id="<?= htmlspecialchars($lastId, ENT_QUOTES, 'UTF-8') ?>">
<header><a class="brand" href="index.php"><span class="mark">م</span><span>ماسك <small>استوديو الفيديو</small></span></a><span class="badge">تجربة أولى · ٠٫١</span></header>
<main>
<section class="intro"><span class="eyebrow">من الفكرة إلى أول لقطة</span><h1>تخيّل المشهد.<br><span>واكتبه بطريقتك.</span></h1><p>صف ما تراه في بالك، وسنحوّل وصفك إلى مقطع تستطيع مشاهدته وتنزيله هنا.</p></section>
<div class="workspace">
<section class="card composer"><div class="section-title"><span class="number">١</span><h2>وصف الفيديو</h2></div>
<form id="generate-form"><label for="prompt">ماذا تريد أن يظهر في المقطع؟</label>
<textarea id="prompt" name="prompt" maxlength="1000" rows="7" required placeholder="صف المكان، الشخصية أو المنتج، حركة الكاميرا، والإضاءة..."></textarea>
<div class="hint"><span>وصف أوضح يعطي المشهد اتجاهًا أوضح.</span><span id="counter">0 / 1000</span></div>
<button type="button" id="example" class="text-button">جرّب وصف السيارة السينمائي</button>
<div class="settings"><span>عمودي <b dir="ltr"><?= $config['ratio'] === '720:1280' ? '9:16' : '16:9' ?></b></span><span><?= $config['duration'] ?> ثوانٍ</span><span dir="ltr">Gen-4.5</span></div>
<button id="generate" class="primary" type="submit" <?= !$ready ? 'disabled' : '' ?>>إنشاء أول مقطع <span aria-hidden="true">↗</span></button>
<p class="fine">التوليد يستهلك من رصيد حساب Runway. لن يُنشأ طلب جديد بمجرد تحديث الصفحة.</p></form>
<?php if (!$ready): ?><div class="setup" role="status"><strong>خطوة الإعداد المتبقية</strong><p>انسخ <b dir="ltr">config.example.php</b> إلى <b dir="ltr">config.local.php</b> وأضف مفتاح Runway على جهازك. ثم حدّث الصفحة.</p></div><?php endif; ?>
</section>
<section class="card result"><div class="section-title"><span class="number">٢</span><h2>أول مقطع لك</h2></div>
<div id="placeholder" class="preview"><div class="preview-icon" aria-hidden="true">▷</div><strong>هنا ستظهر فكرتك</strong><p>أول لقطة تبدأ من وصفك.</p></div>
<video id="video" controls playsinline preload="metadata" hidden aria-label="الفيديو الناتج"></video>
<div id="status" role="status" aria-live="polite">جاهز لوصفك</div>
<p id="detail" class="fine"></p><p id="error" class="error" role="alert" hidden></p>
<button id="resume" type="button" class="secondary" hidden>متابعة نفس الطلب</button>
<a id="download" class="primary download" hidden>تنزيل الفيديو</a>
</section></div>
<footer>هدف هذه المرحلة: وصف واحد ← فيديو حقيقي واحد. <span>نسخة محلية خاصة بصاحب المشروع.</span></footer>
</main></body></html>
