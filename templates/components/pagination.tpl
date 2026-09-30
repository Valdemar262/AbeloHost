{if $pagination.count > 1}
    <nav class="pagination" aria-label="Страницы категории">
        {if $pagination.previousUrl}
            <a class="page-link" href="{$pagination.previousUrl}" rel="prev">Назад</a>
        {/if}
        {foreach $pagination.links as $link}
            {if $link.gap}
                <span class="pagination-gap">…</span>
            {elseif $link.number === $pagination.current}
                <span class="page-link current" aria-current="page" aria-label="Страница {$link.number}">
                    {$link.number}
                </span>
            {else}
                <a class="page-link" href="{$link.url}" aria-label="Страница {$link.number}">{$link.number}</a>
            {/if}
        {/foreach}
        {if $pagination.nextUrl}
            <a class="page-link" href="{$pagination.nextUrl}" rel="next">Вперёд</a>
        {/if}
        <span class="page-summary">Страница {$pagination.current} из {$pagination.count}</span>
    </nav>
{/if}
