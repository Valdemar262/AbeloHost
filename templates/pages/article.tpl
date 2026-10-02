{extends file="layout.tpl"}

{block name="content"}
    <nav class="breadcrumbs" aria-label="Хлебные крошки">
        <a href="/">Главная</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Статья</span>
    </nav>

    <article class="article-detail">
        <header class="article-header">
            <ul class="article-categories" aria-label="Категории статьи">
                {foreach $categories as $category}
                    <li><a href="/category/{$category.slug|escape:'url'}">{$category.name}</a></li>
                {/foreach}
            </ul>
            <h1>{$post.title}</h1>
            <p class="article-description">{$post.description}</p>
            <div class="post-meta">
                <time datetime="{$post.published_at|date_format:'%Y-%m-%dT%H:%M:%SZ'}">
                    {$post.published_at|date_format:'%d.%m.%Y'}
                </time>
                <span>Просмотры: <span data-article-views>{$post.views}</span></span>
            </div>
        </header>
        <img class="article-image" src="{$post.image_path}" alt="{$post.title}" width="1200" height="720">
        <div class="article-body">
            {foreach $paragraphs as $paragraph}
                <p>{$paragraph}</p>
            {/foreach}
        </div>
    </article>

    {if $relatedPosts}
        <section class="related-posts" aria-labelledby="related-heading">
            <div class="section-heading">
                <h2 id="related-heading">Похожие статьи</h2>
            </div>
            <div class="post-grid">
                {foreach $relatedPosts as $relatedPost}
                    {include file="components/post-card.tpl" post=$relatedPost headingLevel=3}
                {/foreach}
            </div>
        </section>
    {/if}
{/block}
