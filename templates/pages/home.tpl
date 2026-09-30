{extends file="layout.tpl"}

{block name="content"}
    <header class="page-intro">
        <p class="eyebrow">Идеи, практика, опыт</p>
        <h1>Блог о веб-разработке</h1>
        <p>Свежие статьи о PHP, базах данных, архитектуре и интерфейсах.</p>
    </header>

    {foreach $categories as $category}
        <section class="category-section" aria-labelledby="category-{$category.id}" data-category="{$category.slug}">
            <div class="section-heading">
                <div>
                    <h2 id="category-{$category.id}">{$category.name}</h2>
                    <p class="muted">{$category.description}</p>
                </div>
                <a class="button button-secondary" href="/category/{$category.slug|escape:'url'}"
                   aria-label="Все статьи: {$category.name}">Все статьи <span aria-hidden="true">→</span></a>
            </div>
            <div class="post-grid">
                {foreach $category.posts as $post}
                    {include file="components/post-card.tpl" post=$post headingLevel=3}
                {/foreach}
            </div>
        </section>
    {foreachelse}
        <div class="panel empty-state">
            <h2>Пока нет опубликованных статей</h2>
            <p>Загляните позже — здесь появятся новые материалы.</p>
        </div>
    {/foreach}
{/block}
