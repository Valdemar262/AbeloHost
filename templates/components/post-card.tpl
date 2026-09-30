<article class="post-card" data-post-id="{$post.id}">
    <a class="post-image-link" href="/article/{$post.slug|escape:'url'}" tabindex="-1" aria-hidden="true">
        <img class="post-image" src="{$post.image_path}" alt="" width="1200" height="720" loading="lazy">
    </a>
    <div class="post-content">
        <div class="post-meta">
            <time datetime="{$post.published_at|date_format:'%Y-%m-%dT%H:%M:%SZ'}">
                {$post.published_at|date_format:'%d.%m.%Y'}
            </time>
            <span>Просмотры: {$post.views}</span>
        </div>
        {if $headingLevel === 2}
            <h2 class="post-title"><a href="/article/{$post.slug|escape:'url'}">{$post.title}</a></h2>
        {else}
            <h3 class="post-title"><a href="/article/{$post.slug|escape:'url'}">{$post.title}</a></h3>
        {/if}
        <p class="post-description">{$post.description}</p>
    </div>
</article>
