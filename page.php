<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php $this->need('public/header.php'); ?>

<div class="container">
    <div class="grid-layout">

        <div class="main-content">
            <article class="card article-card">
                <h1 class="post-title page-title"><?php $this->title(); ?></h1>

                <div class="post-content">
                    <?php $this->content(); ?>
                </div>
            </article>

            <?php $this->need('comments.php'); ?>
        </div>

        <?php $this->need('public/sidebar.php'); ?>

    </div>
</div>

<?php $this->need('public/footer.php'); ?>
