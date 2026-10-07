<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }
$active = AppShell::activeSection();
?>
<!doctype html>
<html <?php language_attributes(); ?> class="ckm-neg-app-html">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="ckm-neg-app-frame">
    <header class="ckm-neg-appbar">
        <div class="ckm-neg-appbar-inner">
            <a class="ckm-neg-brand" href="<?php echo esc_url(ProductCatalog::url()); ?>" aria-label="Мастер переговоров">
                <span class="ckm-neg-brand-mark" aria-hidden="true"><img src="<?php echo esc_url(AppShell::logoUrl()); ?>" alt="" width="42" height="42"></span>
                <span class="ckm-neg-brand-copy"><strong>Мастер переговоров</strong><small>ИИ-тренажёр переговоров</small></span>
            </a>
            <nav class="ckm-neg-appnav" aria-label="Навигация Мастера переговоров">
                <?php foreach (AppShell::nav() as $key => $item): ?>
                    <a class="<?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['label']); ?></a>
                <?php endforeach; ?>
            </nav>
            <a class="ckm-neg-organizer-link" href="<?php echo esc_url(AppShell::organizerUrl()); ?>">Кабинет организатора</a>
        </div>
    </header>
    <main class="ckm-neg-app-main" id="main-content">
        <?php
        while (have_posts()) {
            the_post();
            the_content();
        }
        ?>
    </main>
</div>
<?php wp_footer(); ?>
</body>
</html>
