{extends file="layout.tpl"}

{block name="content"}
    <nav class="breadcrumbs" aria-label="Хлебные крошки">
        <a href="/">Главная</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{$category.name}</span>
    </nav>
    <header class="page-intro">
        <h1>{$category.name}</h1>
        <p>{$category.description}</p>
    </header>

    <div class="listing-toolbar">
        <p class="muted">Всего статей: {$total}</p>
        <form class="sort-form" method="get" action="/category/{$category.slug|escape:'url'}">
            <label for="sort">Сортировка</label>
            <select id="sort" name="sort">
                <option value="date"{if $sort === 'date'} selected{/if}>Сначала новые</option>
                <option value="views"{if $sort === 'views'} selected{/if}>Сначала популярные</option>
            </select>
            <button class="button" type="submit">Применить</button>
        </form>
    </div>

    {if $posts}
        <div class="post-grid">
            {foreach $posts as $post}
                {include file="components/post-card.tpl" post=$post headingLevel=2}
            {/foreach}
        </div>
        {include file="components/pagination.tpl" pagination=$pagination}
    {else}
        <div class="panel empty-state">
            <h2>В этой категории пока нет опубликованных статей</h2>
            <p>Другие материалы можно найти <a href="/">на главной странице</a>.</p>
        </div>
    {/if}
{/block}
