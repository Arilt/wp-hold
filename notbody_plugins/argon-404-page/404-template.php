<?php
/**
 * Argon 404 Page Template
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$home_url  = home_url( '/' );
$back_url  = wp_get_referer() ? wp_get_referer() : $home_url;
$search_url = home_url( '/?s=' );
?>
<!DOCTYPE html>
<html lang="<?php language_attributes(); ?>">
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - <?php bloginfo( 'name' ); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>

<!-- 404 角色头部（带眼睛的动画） -->
<div class="a404-head">
    <meta class="a404-meta"></meta>
    <meta class="a404-meta"></meta>
    <meta class="a404-meta"></meta>
</div>

<!-- 主体内容 -->
<div class="a404-body">

    <!-- <div class="a404-title">404?</div>
    <div class="a404-text">Got lost? How.....? why.....? Ahhhh....</div> -->

    <!-- 搜索框 -->
    <div class="a404-search">
        <form method="get" action="<?php echo esc_url( $search_url ); ?>">
            <input type="search" name="s" placeholder="🔍 搜索你想要的内容..." autocomplete="off">
        </form>
    </div>

    <!-- 操作按钮 -->
    <div class="a404-actions">
        <a href="<?php echo esc_url( $home_url ); ?>" class="a404-btn a404-btn-primary">
            🏠 回到首页
        </a>
        <a href="<?php echo esc_url( $back_url ); ?>" class="a404-btn a404-btn-secondary" onclick="return goBack();">
            ← 返回上一页
        </a>
    </div>
</div>

<script>
function goBack() {
    if ( document.referrer && document.referrer !== window.location.href ) {
        window.history.back();
        return false;
    }
    window.location.href = '<?php echo esc_js( $home_url ); ?>';
    return false;
}
</script>

<?php wp_footer(); ?>
</body>
</html>
