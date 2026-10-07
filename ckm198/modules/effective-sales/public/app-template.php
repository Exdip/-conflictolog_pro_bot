<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }
$teamName=SalesCompetitionService::currentTeamName();
$teamAuth=SalesCompetitionService::currentTeamAuthArgs();
?><!doctype html>
<html <?php language_attributes(); ?> class="ckm-sales-app-html">
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="ckm-sales-frame">
<header class="ckm-sales-appbar">
  <div class="ckm-sales-appbar-inner">
    <a class="ckm-sales-brand" href="<?php echo esc_url(SalesPage::url()); ?>" aria-label="Эффективный продажник">
      <span class="ckm-sales-brand-mark"><img src="<?php echo esc_url(AppShell::logoUrl()); ?>" alt="" width="42" height="42"></span>
      <span class="ckm-sales-brand-copy"><strong>Эффективный продажник</strong><small><?php echo $teamName!==''?'Команда: '.esc_html($teamName):(((string)($_GET['sales_view']??''))==='ai-seller'?'ИИ-продавец':'ИИ-тренажёр продаж'); ?></small></span>
    </a>
    <?php $salesView=(string)($_GET['sales_view']??''); $salesFormat=(string)($_GET['sales_format']??''); $salesWaiting=absint($_GET['sales_waiting_competition']??0); $publicSeller=$salesView==='ai-seller'; ?>
    <?php if($publicSeller): ?>
      <span class="ckm-sales-public-mode">Разговор с ИИ-продавцом</span>
    <?php elseif($teamName!==''): ?>
    <nav class="ckm-sales-nav" aria-label="Навигация команды">
      <a class="is-active" href="<?php echo esc_url(SalesPage::url($teamAuth)); ?>">Соревнование</a>
    </nav>
    <?php else: ?>
    <nav class="ckm-sales-nav" aria-label="Навигация Центра развития продаж">
      <a class="<?php echo $salesView===''||$salesView==='center'?'is-active':''; ?>" href="<?php echo esc_url(SalesPage::url()); ?>">Центр развития продаж</a>
      <a class="<?php echo $salesView==='polygon'?'is-active':''; ?>" href="<?php echo esc_url(SalesPage::url(['sales_view'=>'polygon'])); ?>">Полигон продаж</a>
      <a class="<?php echo $salesView==='competitions'||$salesView==='competition-host'||$salesFormat==='competition'||$salesWaiting>0?'is-active':''; ?>" href="<?php echo esc_url(SalesPage::url(['sales_view'=>'competitions'])); ?>">Соревнования</a>
      <a class="<?php echo $salesView==='scripts'?'is-active':''; ?>" href="<?php echo esc_url(SalesPage::url(['sales_view'=>'scripts'])); ?>">Методики</a>
      <a class="<?php echo $salesView==='ai-sellers'?'is-active':''; ?>" href="<?php echo esc_url(SalesPage::url(['sales_view'=>'ai-sellers'])); ?>">Практика</a>
      <a class="<?php echo $salesView==='results'?'is-active':''; ?>" href="<?php echo esc_url(SalesPage::url(['sales_view'=>'results'])); ?>">Результаты</a>
    </nav>
    <a class="ckm-sales-organizer-link" href="<?php echo esc_url(AppShell::organizerUrl()); ?>">Кабинет организатора</a>
    <?php endif; ?>
  </div>
</header>
<main class="ckm-sales-main" id="main-content">
<?php while(have_posts()){the_post();the_content();} ?>
</main>
</div>
<?php wp_footer(); ?>
</body>
</html>
